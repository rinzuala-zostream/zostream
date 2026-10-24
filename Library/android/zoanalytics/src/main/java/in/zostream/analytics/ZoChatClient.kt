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
            check(response.isSuccessful && json.optBoolean("success")) {
                json.optString("message").takeIf { it.isNotBlank() && it != "null" } ?: "Chat request failed (${response.code})"
            }
            json.getJSONObject("data")
        }
    }

    fun join(type: String, contentId: String?, callback: (JSONObject?, String?) -> Unit) {
        owner = credentials()?.ownerKey
        if (type == "invite") {
            val roomId = contentId ?: return callback(null, "Invite room unavailable")
            request("invite-rooms/$roomId/join", JSONObject()) { data, error ->
                room = data; callback(data, error)
                if (data != null) {
                    connect()
                    main.removeCallbacks(reconcile); main.postDelayed(reconcile, 30_000)
                }
            }
            return
        }
        val body = JSONObject().put("type", type)
        if (contentId != null) body.put("content_id", contentId)
        request("rooms/join", body) { data, error ->
            room = data; callback(data, error)
            if (data != null) {
                connect()
                main.removeCallbacks(reconcile); main.postDelayed(reconcile, 30_000)
            }
        }
    }

    private fun connect() {
        if (closed) return
        val info = room ?: return
        val url = info.optString("websocket_url")
        if (!url.startsWith("wss://")) { onConnection(false); return }
        val current = ++generation
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
                                request("rooms/${info.getString("room_id")}/auth", JSONObject().put("socket_id", data.getString("socket_id"))) { auth, _ ->
                                    if (auth != null) webSocket.send(JSONObject().put("event", "pusher:subscribe")
                                        .put("data", JSONObject().put("channel", auth.getString("channel")).put("auth", auth.getString("auth"))).toString())
                                    else { onConnection(false); webSocket.cancel(); reconnect(current) }
                                }
                            }
                            "pusher_internal:subscription_succeeded" -> { retry = 0; onConnection(true); onChanged("sync", null) }
                            "pusher:ping" -> webSocket.send("{\"event\":\"pusher:pong\",\"data\":{}}")
                            "pusher:error" -> { webSocket.cancel(); reconnect(current) }
                            "chat.changed" -> {
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
        generation++
        onConnection(false)
        val delay = (1000L shl retry.coerceAtMost(5)) + (0..500).random()
        retry++
        main.postDelayed({ if (!closed) connect() }, delay)
    }

    override fun close() {
        if (closed) return
        closed = true; generation++; main.removeCallbacksAndMessages(null)
        socket?.cancel(); pendingCall?.cancel(); executor.shutdownNow()

    }
}
