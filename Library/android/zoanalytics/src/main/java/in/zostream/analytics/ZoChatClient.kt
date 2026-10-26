package `in`.zostream.analytics

import android.os.Handler
import android.os.Looper
import okhttp3.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject
import java.io.Closeable
import java.util.concurrent.Executors
import java.util.concurrent.TimeUnit

/** Independent of the analytics kill switch. Credentials are resolved afresh for each request. */
class ZoChatClient(
    private val baseUrl: String,
    private val credentials: () -> AnalyticsCredentials?,
    private val version: String,
    private val playbackToken: () -> String? = { null },
    private val onChanged: (String, String?) -> Unit,
    private val onConnection: (Boolean) -> Unit,
    private val http: OkHttpClient = OkHttpClient.Builder().connectTimeout(10, TimeUnit.SECONDS).readTimeout(15, TimeUnit.SECONDS).callTimeout(20, TimeUnit.SECONDS).build(),
) : Closeable {
    init { require(baseUrl.startsWith("https://")) { "Chat API must use HTTPS" } }
    private val main = Handler(Looper.getMainLooper())
    private val executor = Executors.newSingleThreadExecutor()
    private var socket: WebSocket? = null
    private var room: JSONObject? = null
    @Volatile private var closed = false
    private var generation = 0
    private var retry = 0
    private var roomRevision = 0
    private var socketId: String? = null
    private var socketUrl: String? = null
    private var channel: String? = null
    @Volatile private var owner: String? = null
    @Volatile private var pendingCall: Call? = null
    private val reconcile = object : Runnable {
        override fun run() {
            if (closed) return
            if (credentials()?.ownerKey != owner) { close(); return }
            room?.let { request("rooms/${it.getString("room_id")}/heartbeat", JSONObject()) { _, _ -> } }
            onChanged("sync", null)
            main.postDelayed(this, 30_000)
        }
    }

    fun request(path: String, body: JSONObject? = null, callback: (JSONObject?, String?) -> Unit) {
        if (closed) return
        executor.execute {
            val result = runCatching { perform(path, body) }
            main.post {
                if (!closed)
                    result.fold({ callback(it, null) }, { callback(null, it.message ?: "Chat unavailable") })
            }
        }
    }

    private fun perform(path: String, body: JSONObject?): JSONObject {
        val c = credentials() ?: error("Please sign in again")
        check(owner == null || owner == c.ownerKey) { "Account changed. Reopen chat." }
        val request = Request.Builder().url(baseUrl.trimEnd('/') + "/api/v4/chat/" + path)
            .header("Authorization", "Bearer " + c.accessToken.removePrefix("Bearer "))
            .header("Device-Token", c.deviceToken).header("Accept", "application/json")
            .header("X-Analytics-SDK-Version", ZoAnalyticsConfiguration.SDK_VERSION)
            .header("X-Client-Platform", "android").header("X-Client-Version", version)
        playbackToken()?.takeIf { it.isNotBlank() }?.let { request.header("X-Playback-Token", it) }
        if (body != null) request.post(body.toString().toRequestBody("application/json".toMediaType()))
        val call = http.newCall(request.build()); pendingCall = call
        return call.execute().use { response ->
            val json = JSONObject(response.body?.string() ?: "{}")
            val data = json.optJSONObject("data")
            val successValue = json.opt("success")
            val declaredSuccess = when (successValue) {
                is Boolean -> successValue
                is Number -> successValue.toInt() != 0
                is String -> successValue.equals("true", ignoreCase = true) || successValue == "1"
                else -> json.optString("status").equals("success", ignoreCase = true)
            }
            // Chat endpoints on some deployed API versions return their payload directly.
            val successUndeclared = successValue == null || successValue == JSONObject.NULL
            val payloadKeys = listOf(
                "room_id", "user_id", "messages", "invites", "accepted", "position_ms",
                "online_count", "auth", "blocked", "left", "users", "reported", "resolved", "updated"
            )
            val hasDirectPayload = payloadKeys.any { key -> json.has(key) && json.opt(key) != JSONObject.NULL }
            val successfulEnvelope = declaredSuccess || (successUndeclared && (data != null || hasDirectPayload))
            check(response.isSuccessful && successfulEnvelope) {
                val nestedError = json.optJSONObject("error")?.optString("message")
                val message = json.optString("message").takeIf { it.isNotBlank() && it != "null" }
                    ?: nestedError?.takeIf { it.isNotBlank() && it != "null" }
                val keys = json.keys().asSequence().joinToString(",")
                val diagnostic = "Chat request failed (${response.code}) at $path (success=$successValue, status=${json.optString("status")}, fields=$keys)"
                android.util.Log.e("ZoChatClient", diagnostic)
                message ?: diagnostic
            }
            data ?: json.optJSONObject("result") ?: json
        }
    }

    fun join(type: String, contentId: String?, callback: (JSONObject?, String?) -> Unit) {
        if (closed) { callback(null, "Chat connection closed"); return }
        val identity = credentials()?.ownerKey
        if (owner != null && owner != identity) { close(); callback(null, "Account changed. Reopen chat."); return }
        owner = identity
        val revision = ++roomRevision
        channel?.let { sendEvent("pusher:unsubscribe", JSONObject().put("channel", it)) }
        channel = null; room = null
        val path: String
        val body = JSONObject()
        if (type == "invite") {
            val id = contentId ?: return callback(null, "Invite room unavailable")
            path = "invite-rooms/$id/join"
        } else {
            path = "rooms/join"
            body.put("type", type)
            if (contentId != null) body.put("content_id", contentId)
        }
        request(path, body) { data, error ->
            if (revision != roomRevision) return@request
            room = data
            callback(data, error)
            if (data != null && revision == roomRevision && !closed) {
                if (socket != null && socketUrl == data.optString("websocket_url")) subscribe()
                else connect()
                main.removeCallbacks(reconcile); main.postDelayed(reconcile, 30_000)
            }
        }
    }

    private fun sendEvent(event: String, data: JSONObject): Boolean =
        socket?.send(JSONObject().put("event", event).put("data", data).toString()) == true

    private fun subscribe() {
        val id = socketId ?: return // A handshake already in flight will subscribe to the latest room.
        val info = room ?: return
        val revision = roomRevision
        val current = generation
        request("rooms/${info.getString("room_id")}/auth", JSONObject().put("socket_id", id)) { auth, _ ->
            if (closed || current != generation || revision != roomRevision) return@request
            if (auth == null || auth.optString("channel") != info.optString("channel")) {
                reconnect(current); return@request
            }
            channel = auth.getString("channel")
            if (!sendEvent("pusher:subscribe", JSONObject().put("channel", channel).put("auth", auth.getString("auth")))) reconnect(current)
        }
    }

    private fun connect() {
        if (closed) return
        val info = room ?: return
        val url = info.optString("websocket_url")
        if (!url.startsWith("wss://")) { onConnection(false); return }
        val current = ++generation
        socketId = null; channel = null; socketUrl = url
        socket?.cancel()
        socket = http.newWebSocket(Request.Builder().url(url).header("Origin", baseUrl.trimEnd('/')).build(), object : WebSocketListener() {
            override fun onMessage(webSocket: WebSocket, text: String) {
                main.post {
                    if (closed || current != generation) return@post
                    runCatching {
                        val event = JSONObject(text)
                        when (event.optString("event")) {
                            "pusher:connection_established" -> {
                                val data = JSONObject(event.getString("data"))
                                socketId = data.getString("socket_id")
                                subscribe()
                            }
                            "pusher_internal:subscription_succeeded" -> {
                                if (channel != null && event.optString("channel") == channel) {
                                    retry = 0; onConnection(true); onChanged("sync", null)
                                }
                                Unit
                            }
                            "pusher:ping" -> webSocket.send("{\"event\":\"pusher:pong\",\"data\":{}}")
                            "pusher:error" -> { webSocket.cancel(); reconnect(current) }
                            "chat.changed" -> {
                                if (channel == null || event.optString("channel") != channel) return@post
                                val data = event.opt("data").let { if (it is JSONObject) it else JSONObject(it?.toString() ?: "{}") }
                                onChanged(data.getString("kind"), data.optString("message_id").takeIf { it != "null" && it.isNotEmpty() })
                            }
                            else -> Unit
                        }
                    }
                }
            }
            override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) { main.post { reconnect(current) } }
            override fun onClosed(webSocket: WebSocket, code: Int, reason: String) { main.post { reconnect(current) } }
        })
    }

    private fun reconnect(current: Int) {
        if (closed || current != generation) return
        val waiting = ++generation
        socket?.cancel(); socket = null; socketId = null; channel = null
        onConnection(false)
        val delay = (1000L shl retry.coerceAtMost(5)) + (0..500).random()
        retry++
        main.postDelayed({ if (!closed && generation == waiting) connect() }, delay)
    }

    override fun close() {
        if (closed) return
        closed = true; generation++; roomRevision++; main.removeCallbacksAndMessages(null)
        channel = null; socketId = null; room = null
        socket?.cancel(); pendingCall?.cancel(); executor.shutdownNow()

    }
}
