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
    var onBadgeEvent: ((JSONObject) -> Unit)? = null
    var onUnreadRoomChange: ((JSONObject) -> Unit)? = null
    var onMessage: ((JSONObject) -> Unit)? = null
    var onTyping: ((org.json.JSONArray) -> Unit)? = null
    var onOverlayMessage: ((JSONObject) -> Unit)? = null
    private var controlToken: String? = null
    private var controlChannel: String? = null
    private var controlSubscribed = false
    private var feedChannel: String? = null
    private var feedSubscribed = false
    private var typingVersion = 0L
    private var recoveryDelay = 5_000L
    private val liveVersions = linkedMapOf<String, Long>()
    private val liveKinds = linkedMapOf<String, String>()
    private val pendingLive = linkedMapOf<String, String>()
    private val initialLiveRecoveryDelay = 500L
    private var liveRecoveryDelay = initialLiveRecoveryDelay
    private val liveRecovery = Runnable {
        if (pendingLive.isNotEmpty() && roomVisible != false && !closed) {
            val deleted = pendingLive.values.any { it == "deleted" }
            pendingLive.clear()
            liveRecoveryDelay = (liveRecoveryDelay * 2).coerceAtMost(30_000)
            onChanged(if (deleted) "reconcile" else "created", null)
        }
    }
    private var unreadChannels: Set<String>? = null
    private var batchAuthorization = false
    private val unreadSent = mutableSetOf<String>()
    private val unreadSubscribed = mutableSetOf<String>()
    private val unreadPhysical = mutableMapOf<String, String>()
    private val unreadExpiry = mutableMapOf<String, Long>()
    private var subscriptionExpiry = 0L
    private var subscriptionPending = false
    private class HttpFailure(val status: Int, message: String) : IllegalStateException(message)

    private fun revokeRoom() {
        stopFirebase()
        cancelVisibilityFallback()
        channel?.let { sendEvent("pusher:unsubscribe", JSONObject().put("channel", it)) }
        roomRevision++; room = null; channel = null; controlToken = null; controlChannel?.let { sendEvent("pusher:unsubscribe", JSONObject().put("channel", it)) }; controlChannel = null; clearFeed(); isConnected = false
        onConnection(false); onChanged("access_denied", null)
    }

    private fun matchesChannel(actual: String, logical: String) =
        actual == logical || (actual.startsWith("$logical.lease.") && actual.removePrefix("$logical.lease.").toLongOrNull() != null)
    var onRoomInviteState: ((JSONObject) -> Unit)? = null
    var onWatchState: ((JSONObject) -> Unit)? = null
    var isConnected = false
        private set
    var firebaseSource: ChatFirebaseSource? = null
    @Volatile var watchSocket = false
    var usesFirebaseRealtime = false
        private set
    private var firebaseStream: ChatFirebaseStream? = null
    private var firebaseCursor = 0L
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
    private var lastSocketActivity = 0L
    private var lastFallbackSync = 0L
    private var lastTranscriptReconcile = android.os.SystemClock.elapsedRealtime()
    private var hasSubscribedRoom = false
    // Keep an open transcript current while the independent WebSocket transport
    // is reconnecting. Presence, leases and room-list recovery retain their cadence.
    private val recoverTranscript = object : Runnable {
        override fun run() {
            if (closed) return
            if (credentials()?.ownerKey != owner) { close(); return }
            if (room != null && unreadChannels == null && roomVisible != false && !isConnected) {
                onChanged("poll", null)
                recoveryDelay = (recoveryDelay * 2).coerceAtMost(60_000)
            } else recoveryDelay = 5_000
            main.postDelayed(this, recoveryDelay + if (recoveryDelay > 5_000) (0..(recoveryDelay / 5).toInt()).random() else 0)
        }
    }
    private val reconcile = object : Runnable {
        override fun run() {
            if (closed) return
            if (credentials()?.ownerKey != owner) { close(); return }
            room?.takeIf { unreadChannels == null && roomVisible != false }?.let {
                if (controlToken != null && isConnected) reportVisibility(it.getString("room_id"), roomVisible ?: false)
                else request("rooms/${it.getString("room_id")}/heartbeat", roomVisible?.let { visible -> visibilityBody(visible) } ?: JSONObject()) { _, _ -> }
            }
            val now = android.os.SystemClock.elapsedRealtime()
            if (room != null && (unreadChannels != null || !subscriptionPending) &&
                (if (unreadChannels != null) unreadExpiry.values.any { it <= now } else subscriptionExpiry <= now)) subscribe()
            if (room != null && unreadChannels == null && isConnected && now - lastTranscriptReconcile >= 300_000) {
                lastTranscriptReconcile = now; onChanged("reconcile", null)
            }
            if (isConnected && !usesFirebaseRealtime) {
                if (now - lastSocketActivity > 90_000) reconnect(generation)
                else sendEvent("pusher:ping", JSONObject())
            }
            if (unreadChannels != null && !isConnected && now - lastFallbackSync >= 60_000) {
                lastFallbackSync = now; onChanged("sync", null)
            }
            main.postDelayed(this, 30_000)
        }
    }

    fun request(path: String, body: JSONObject? = null, callback: (JSONObject?, String?) -> Unit) {
        if (closed) return
        if (usesFirebaseRealtime && path == "rooms/${room?.optString("room_id")}/typing" && body != null &&
            credentials()?.let { owner == null || owner == it.ownerKey } == true &&
            firebaseSource?.writeTyping(room!!.getString("room_id"), body.optString("typing_session_id"), body.optLong("typing_sequence"), body.optBoolean("active")) == true) {
            callback(JSONObject().put("users", org.json.JSONArray()), null)
            return
        }
        val requestOwner = credentials()?.ownerKey
        val requestPlaybackToken = playbackToken()
        val requestRoomRevision = roomRevision
        executor.execute {
            val result = runCatching { perform(path, body, requestOwner, requestPlaybackToken) }
            main.post {
                if (!closed && credentials()?.ownerKey == requestOwner)
                    result.fold({ callback(it, null) }, {
                        if (unreadChannels == null && requestRoomRevision == roomRevision && it is HttpFailure &&
                            it.status in listOf(403, 404, 410) && room?.optString("room_id")?.let { id ->
                                path == "rooms/$id/heartbeat" || path == "rooms/$id/auth"
                            } == true) revokeRoom()
                        callback(null, it.message ?: "Chat unavailable")
                    })
            }
        }
    }

    /** Final stop is independent of the room request queue so close cannot cancel it. */
    fun stopTyping(roomId: String, sessionId: String, sequence: Long) {
        val c = credentials()?.takeIf { owner == null || owner == it.ownerKey } ?: return
        if (usesFirebaseRealtime && firebaseSource?.writeTyping(roomId, sessionId, sequence, false) == true) return
        val body = JSONObject().put("active", false).put("typing_session_id", sessionId).put("typing_sequence", sequence)
        val request = Request.Builder().url(baseUrl.trimEnd('/') + "/api/v2/chat/rooms/$roomId/typing")
            .header("Authorization", "Bearer " + c.accessToken.removePrefix("Bearer "))
            .header("Device-Token", c.deviceToken).header("Accept", "application/json")
            .header("X-Client-Platform", "android").header("X-Client-Version", version)
            .post(body.toString().toRequestBody("application/json".toMediaType()))
        playbackToken()?.takeIf { it.isNotBlank() }?.let { request.header("X-Playback-Token", it) }
        http.newCall(request.build()).enqueue(object : Callback {
            override fun onFailure(call: Call, e: java.io.IOException) { }
            override fun onResponse(call: Call, response: Response) { response.close() }
        })
    }

    private fun perform(path: String, body: JSONObject?, requestOwner: String?, requestPlaybackToken: String?): JSONObject {
        val c = credentials() ?: error("Please sign in again")
        check(c.ownerKey == requestOwner) { "Account changed. Reopen chat." }
        check(owner == null || owner == c.ownerKey) { "Account changed. Reopen chat." }
        val request = Request.Builder().url(baseUrl.trimEnd('/') + "/api/v2/chat/" + path)
            .header("Authorization", "Bearer " + c.accessToken.removePrefix("Bearer "))
            .header("Device-Token", c.deviceToken).header("Accept", "application/json")
            .header("X-Analytics-SDK-Version", ZoAnalyticsConfiguration.SDK_VERSION)
            .header("X-Client-Platform", "android").header("X-Client-Version", version)
        requestPlaybackToken?.takeIf { it.isNotBlank() }?.let { request.header("X-Playback-Token", it) }
        request.header("X-Chat-Protocol", "rtdb-2")
        if (watchSocket) request.header("X-Zo-Watch-Socket", "1")
        if (body != null) request.post(body.toString().toRequestBody("application/json".toMediaType()))
        val call = http.newCall(request.build()); pendingCall = call
        return call.execute().use { response ->
            check(!closed && credentials()?.ownerKey == c.ownerKey) { "Account changed. Reopen chat." }
            val json = try { JSONObject(response.body?.string() ?: "{}") }
                catch (_: org.json.JSONException) { error("Chat returned an unreadable response. Please try again.") }
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
                "room_id", "user_id", "messages", "pins", "pinned", "invites", "rooms", "ended", "accepted", "position_ms",
                "online_count", "auth", "blocked", "left", "users", "reported", "resolved", "updated", "movies"
            )
            val hasDirectPayload = payloadKeys.any { key -> json.has(key) && json.opt(key) != JSONObject.NULL }
            val successfulEnvelope = declaredSuccess || (successUndeclared && (data != null || hasDirectPayload))
            check(response.isSuccessful && successfulEnvelope) {
                val nestedError = json.optJSONObject("error")?.optString("message")
                val message = json.optString("message").takeIf { it.isNotBlank() && it != "null" }
                    ?: nestedError?.takeIf { it.isNotBlank() && it != "null" }
                val keys = json.keys().asSequence().joinToString(",")
                val diagnosticPath = if (path.startsWith("invitation-links/")) "invitation-links/[redacted]" else path
                val diagnostic = "Chat request failed (${response.code}) at $diagnosticPath (success=$successValue, status=${json.optString("status")}, fields=$keys)"
                android.util.Log.e("ZoChatClient", diagnostic)
                // Keep the server message when present; otherwise surface the
                // status and endpoint so endpoint-specific failures can be
                // diagnosed from the chat UI on real devices.
                throw HttpFailure(response.code, message ?: diagnostic)
            }
            data ?: json.optJSONObject("result") ?: json
        }
    }

    private var roomVisible: Boolean? = null
    private var visibilitySequence = 0L
    private val visibilityId = java.util.UUID.randomUUID().toString()
    private var visibilityFallbackSequence: Long? = null
    private var visibilityFallback: Runnable? = null
    private fun visibilityBody(visible: Boolean): JSONObject {
        visibilitySequence = maxOf(visibilitySequence + 1, System.currentTimeMillis())
        return JSONObject().put("visible", visible).put("visibility_sequence", visibilitySequence).put("visibility_id", visibilityId)
    }
    private fun cancelVisibilityFallback() {
        visibilityFallback?.let { main.removeCallbacks(it) }
        visibilityFallback = null
        visibilityFallbackSequence = null
    }

    // A room switch or close can interrupt the socket before it acknowledges a hide.
    private fun finishVisibility() {
        val needsHide = roomVisible == true || visibilityFallbackSequence != null
        cancelVisibilityFallback()
        if (needsHide) room?.let { reportVisibilityHTTP(it.getString("room_id"), visibilityBody(false)) }
    }

    private fun reportVisibility(id: String, visible: Boolean) {
        cancelVisibilityFallback()
        val body = visibilityBody(visible)
        val token = controlToken
        val currentChannel = controlChannel
        if (controlSubscribed && token != null && currentChannel != null) {
            val sequence = body.getLong("visibility_sequence")
            val sent = socket?.send(JSONObject().put("event", "chat:presence").put("channel", currentChannel).put("data", body).toString()) == true
            if (sent) {
                visibilityFallbackSequence = sequence
                val fallback = Runnable {
                    if (visibilityFallbackSequence != sequence) return@Runnable
                    visibilityFallbackSequence = null
                    visibilityFallback = null
                    reportVisibilityHTTP(id, body)
                }
                visibilityFallback = fallback
                main.postDelayed(fallback, 2_500)
                return
            }
        }
        reportVisibilityHTTP(id, body)
    }
    private fun reportVisibilityHTTP(id: String, body: JSONObject) {
        val c = credentials() ?: return
        if (owner != null && owner != c.ownerKey) return
        val request = Request.Builder().url(baseUrl.trimEnd('/') + "/api/v2/chat/rooms/$id/heartbeat")
            .header("Authorization", "Bearer " + c.accessToken.removePrefix("Bearer "))
            .header("Device-Token", c.deviceToken).header("Accept", "application/json")
            .header("X-Client-Platform", "android").header("X-Client-Version", version)
            .post(body.toString().toRequestBody("application/json".toMediaType()))
        playbackToken()?.takeIf { it.isNotBlank() }?.let { request.header("X-Playback-Token", it) }
        // Keep the final hidden-state request independent of the closing executor.
        val call = http.newCall(request.build())
        call.timeout().timeout(5, TimeUnit.SECONDS)
        call.enqueue(object : Callback {
            override fun onFailure(call: Call, e: java.io.IOException) = Unit
            override fun onResponse(call: Call, response: Response) { response.close() }
        })
    }
    fun setRoomVisible(visible: Boolean) {
        val changed = roomVisible != visible
        roomVisible = visible
        if (usesFirebaseRealtime) {
            if (visible) startFirebase() else stopFirebase()
        }
        if (!visible) clearFeed()
        else if (onMessage != null && feedChannel == null && isConnected) subscribe()
        if (changed && unreadChannels == null) room?.let { reportVisibility(it.getString("room_id"), visible) }
    }

    fun join(type: String, contentId: String?, callback: (JSONObject?, String?) -> Unit) {
        if (closed) { callback(null, "Chat connection closed"); return }
        val identity = credentials()?.ownerKey
        if (owner != null && owner != identity) { close(); callback(null, "Account changed. Reopen chat."); return }
        owner = identity
        finishVisibility()
        stopFirebase()
        usesFirebaseRealtime = false
        val revision = ++roomRevision
        clearFeed(); controlToken = null; controlChannel?.let { sendEvent("pusher:unsubscribe", JSONObject().put("channel", it)) }; controlChannel = null; liveVersions.clear(); liveKinds.clear(); pendingLive.clear()
        main.removeCallbacks(liveRecovery)
        liveRecoveryDelay = initialLiveRecoveryDelay
        channel?.let { sendEvent("pusher:unsubscribe", JSONObject().put("channel", it)) }
        isConnected = false
        channel = null; room = null; subscriptionExpiry = 0; subscriptionPending = false
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
            if (data != null) roomVisible?.let { reportVisibility(data.getString("room_id"), it) }
            callback(data, error)
            if (data != null && revision == roomRevision && !closed) {
                usesFirebaseRealtime = firebaseSource != null && data.optBoolean("firebase_realtime")
                firebaseCursor = data.optLong("sync_cursor")
                if (usesFirebaseRealtime) startFirebase()
                else if (socket != null && socketUrl == data.optString("websocket_url")) subscribe()
                else connect()
                main.removeCallbacks(reconcile); main.postDelayed(reconcile, 30_000)
                main.removeCallbacks(recoverTranscript); main.postDelayed(recoverTranscript, 5_000)
            }
        }
    }

    /** One socket observes all authorized badge channels without joining room presence. */
    fun configureUnread(summary: JSONObject) {
        if (closed) return
        batchAuthorization = summary.optBoolean("auth_batch")
        val identity = credentials()?.ownerKey ?: return
        if (owner != null && owner != identity) { close(); return }
        owner = identity
        if (firebaseSource != null && summary.optBoolean("firebase_realtime")) {
            usesFirebaseRealtime = true
            main.removeCallbacks(reconcile)
            unreadChannels = emptySet()
            room = summary
            firebaseCursor = summary.optLong("event_cursor")
            firebaseStream?.updateAccountCursor(firebaseCursor)
            startFirebase()
            return
        }
        if (usesFirebaseRealtime) stopFirebase()
        usesFirebaseRealtime = false
        val list = summary.optJSONArray("channels") ?: return
        val wanted = (0 until list.length()).map { list.getString(it) }.toSet()
        val previous = unreadChannels.orEmpty()
        if ((wanted - previous).isNotEmpty()) isConnected = false
        (previous - wanted).forEach { target ->
            unreadPhysical.remove(target)?.let { sendEvent("pusher:unsubscribe", JSONObject().put("channel", it)) }
            unreadExpiry.remove(target)
        }
        unreadSent.retainAll(wanted); unreadSubscribed.retainAll(wanted)
        unreadChannels = wanted
        room = summary
        if (socket != null && socketUrl == summary.optString("websocket_url")) subscribe()
        else connect()
        main.removeCallbacks(reconcile); main.postDelayed(reconcile, 30_000)
    }

    private fun startFirebase() {
        if (firebaseStream != null || closed || !usesFirebaseRealtime) return
        val source = firebaseSource ?: return
        val identity = owner ?: return
        val roomId = if (unreadChannels == null) room?.optString("room_id") ?: return else null
        if (roomId != null && roomVisible == false) return
        if (socket != null) {
            generation++
            socket?.cancel(); socket = null; socketId = null; socketUrl = null
            controlToken = null
        }
        val revision = roomRevision
        firebaseStream = ChatFirebaseStream(source, identity, roomId, firebaseCursor,
            { !closed && owner == credentials()?.ownerKey && roomRevision == revision },
            { path, body, callback -> request(path, body, callback) },
            { live -> if (isConnected != live) { isConnected = live; onConnection(live) } },
            { kind, id ->
                when (kind) {
                    "access_denied" -> revokeRoom()
                    "room_state_invalidated" -> onBadgeEvent?.invoke(JSONObject().put("kind", "sync"))
                    else -> onChanged(kind, id)
                }
            },
            { message, overlay ->
                onMessage?.invoke(message)
                if (overlay && !message.optBoolean("deleted")) onOverlayMessage?.invoke(message)
            },
            { users -> onTyping?.invoke(users) },
        ).also { it.start() }
    }

    private fun stopFirebase() {
        val stream = firebaseStream ?: return
        firebaseCursor = stream.lastCursor
        stream.close()
        firebaseStream = null
        isConnected = false
    }

    private fun sendEvent(event: String, data: JSONObject): Boolean =
        socket?.send(JSONObject().put("event", event).put("data", data).toString()) == true

    private fun subscribe() {
        val id = socketId ?: return // A handshake already in flight will subscribe to the latest room.
        unreadChannels?.let { wanted ->
            val current = generation
            val targets = wanted.filter { it !in unreadSent && (it !in unreadSubscribed || (unreadExpiry[it] ?: 0) <= android.os.SystemClock.elapsedRealtime()) }
            fun apply(target: String, auth: JSONObject?) {
                    if (closed || current != generation || target !in unreadChannels.orEmpty()) return
                    unreadSent.remove(target)
                    if (auth == null || !matchesChannel(auth.optString("channel"), target)) {
                        unreadPhysical.remove(target)?.let { sendEvent("pusher:unsubscribe", JSONObject().put("channel", it)) }
                        unreadSubscribed.remove(target); unreadExpiry[target] = 0; isConnected = false
                        onChanged("sync", null); return
                    }
                    val physical = auth.getString("channel")
                    val old = unreadPhysical.put(target, physical)
                    unreadExpiry[target] = android.os.SystemClock.elapsedRealtime() + auth.optLong("expires_in", 300).coerceIn(1, 300) * 1000 + (if (batchAuthorization || auth.has("control_token")) (0..5_000).random() else 0)
                    if (old != physical || target !in unreadSubscribed) {
                        isConnected = false
                        unreadSubscribed.remove(target)
                        unreadSent.add(target)
                        if (!sendEvent("pusher:subscribe", JSONObject().put("channel", physical).put("auth", auth.getString("auth")))) reconnect(current)
                        if (old != null && old != physical) sendEvent("pusher:unsubscribe", JSONObject().put("channel", old))
                    }

            }
            unreadSent.addAll(targets)
            if (batchAuthorization && targets.isNotEmpty()) {
                targets.chunked(64).forEach { batch ->
                    request("unread/auth-batch", JSONObject().put("socket_id", id).put("channels", org.json.JSONArray(batch))) { result, _ ->
                        val authorizations = result?.optJSONObject("authorizations")
                        batch.forEach { apply(it, authorizations?.optJSONObject(it)) }
                    }
                }
            } else targets.forEach { target ->
                request("unread/auth", JSONObject().put("socket_id", id).put("channel", target)) { auth, _ -> apply(target, auth) }
            }
            return
        }
        val info = room ?: return
        if (subscriptionPending) return
        subscriptionPending = true
        val revision = roomRevision
        val current = generation
        request("rooms/${info.getString("room_id")}/auth", JSONObject().put("socket_id", id).put("protocol", 2).put("messages", onMessage != null && roomVisible != false)) { auth, _ ->
            if (closed || current != generation || revision != roomRevision) return@request
            subscriptionPending = false
            if (auth == null || !matchesChannel(auth.optString("channel"), info.optString("channel"))) {
                reconnect(current); return@request
            }
            controlToken = auth.optString("control_token").takeIf { it.isNotBlank() && it != "null" }
            val nextControl = auth.optString("control_channel").takeIf { it.isNotBlank() && it != "null" }
            if (nextControl != null) {
                controlSubscribed = false
                controlChannel?.takeIf { it != nextControl }?.let { sendEvent("pusher:unsubscribe", JSONObject().put("channel", it)) }
                controlChannel = nextControl
                sendEvent("pusher:subscribe", JSONObject().put("channel", nextControl).put("auth", auth.optString("control_auth")))
            }
            val nextFeed = auth.optString("feed_channel").takeIf { it.isNotBlank() && it != "null" }
            if (nextFeed != feedChannel) {
                clearFeed()
                feedChannel = nextFeed
                if (nextFeed != null) sendEvent("pusher:subscribe", JSONObject().put("channel", nextFeed).put("auth", auth.optString("feed_auth")))
            }
            val old = channel
            channel = auth.getString("channel")
            subscriptionExpiry = android.os.SystemClock.elapsedRealtime() + auth.optLong("expires_in", 300).coerceIn(1, 300) * 1000 + (if (batchAuthorization || auth.has("control_token")) (0..5_000).random() else 0)
            if (old == channel && isConnected) return@request
            isConnected = false
            if (old != null && old != channel) sendEvent("pusher:unsubscribe", JSONObject().put("channel", old))
            if (!sendEvent("pusher:subscribe", JSONObject().put("channel", channel).put("auth", auth.getString("auth")))) reconnect(current)
        }
    }

    private fun clearFeed() {
        feedChannel?.let { sendEvent("pusher:unsubscribe", JSONObject().put("channel", it)) }
        feedChannel = null; feedSubscribed = false; typingVersion = 0L
    }

    private fun connect() {
        if (usesFirebaseRealtime || (firebaseSource != null && !watchSocket)) return
        if (closed) return
        val info = room ?: return
        val url = info.optString("websocket_url")
        if (!url.startsWith("wss://")) { onConnection(false); return }
        val current = ++generation
        hasSubscribedRoom = false
        socketId = null; channel = null; controlToken = null; controlChannel = null; controlSubscribed = false; clearFeed(); socketUrl = url
        unreadSent.clear(); unreadSubscribed.clear(); unreadPhysical.clear(); unreadExpiry.clear(); subscriptionPending = false; subscriptionExpiry = 0
        socket?.cancel()
        socket = ChatSocketPool.open(http, Request.Builder().url(url).header("Origin", baseUrl.trimEnd('/')).build(), owner.orEmpty(), object : WebSocketListener() {
            override fun onMessage(webSocket: WebSocket, text: String) {
                main.post {
                    if (closed || current != generation) return@post
                    if (credentials()?.ownerKey != owner) { close(); return@post }
                    lastSocketActivity = android.os.SystemClock.elapsedRealtime()
                    runCatching {
                        val event = JSONObject(text)
                        when (event.optString("event")) {
                            "pusher:connection_established" -> {
                                val data = JSONObject(event.getString("data"))
                                socketId = data.getString("socket_id")
                                subscribe()
                            }
                            "pusher_internal:subscription_succeeded" -> {
                                if (controlChannel != null && event.optString("channel") == controlChannel) {
                                    controlSubscribed = true
                                    room?.let { currentRoom -> roomVisible?.let { reportVisibility(currentRoom.getString("room_id"), it) } }
                                    return@post
                                }
                                if (feedChannel != null && event.optString("channel") == feedChannel) {
                                    feedSubscribed = true
                                    onChanged("sync", null)
                                    return@post
                                }
                                val target = event.optString("channel")
                                if (unreadChannels != null) {
                                    val logical = unreadPhysical.entries.firstOrNull { it.value == target }?.key
                                    if (logical != null && logical in unreadSent && logical in unreadChannels.orEmpty()) {
                                        unreadSent.remove(logical)
                                        unreadSubscribed.add(logical)
                                        if (!isConnected && unreadSubscribed.containsAll(unreadChannels.orEmpty())) {
                                            retry = 0; isConnected = true; onConnection(true); onChanged("sync", null)
                                        }
                                    }
                                    return@post
                                }
                                if (channel != null && event.optString("channel") == channel) {
                                    val recovery = !hasSubscribedRoom
                                    hasSubscribedRoom = true
                                    retry = 0; isConnected = true; onConnection(true)
                                    onChanged(if (recovery) "sync" else "subscribed", null)
                                }
                                Unit
                            }
                            "pusher:ping" -> webSocket.send("{\"event\":\"pusher:pong\",\"data\":{}}")
                            "pusher:error" -> { webSocket.cancel(); reconnect(current) }
                            "chat:presence_ack" -> {
                                val ack = event.optJSONObject("data")
                                val pending = visibilityFallbackSequence
                                if (pending != null && event.optString("channel") == controlChannel &&
                                    ack?.optString("visibility_id") == visibilityId && ack.optLong("visibility_sequence") >= pending) {
                                    cancelVisibilityFallback()
                                }
                                Unit
                            }
                            "chat.typing" -> {
                                if (!feedSubscribed || event.optString("channel") != feedChannel) return@post
                                val data = event.opt("data").let { if (it is JSONObject) it else JSONObject(it?.toString() ?: "{}") }
                                val version = data.optLong("event_id")
                                val users = data.optJSONArray("users") ?: return@post
                                if (data.optString("room_id") != room?.optString("room_id") || version <= typingVersion) return@post
                                typingVersion = version
                                if ((0 until users.length()).any { users.optJSONObject(it)?.opt("is_admin") !is Boolean }) {
                                    onChanged("typing", null)
                                } else onTyping?.invoke(users)
                            }
                            "chat.message" -> {
                                if (feedSubscribed && event.optString("channel") == feedChannel) {
                                    val data = event.opt("data").let { if (it is JSONObject) it else JSONObject(it?.toString() ?: "{}") }
                                    val message = data.optJSONObject("message") ?: return@post
                                    val messageId = message.optString("id")
                                    val version = data.optLong("event_id")
                                    if (data.optString("room_id") != room?.optString("room_id") || version <= (liveVersions[messageId] ?: -1)
                                        || liveKinds[messageId] == "deleted" && !message.optBoolean("deleted")) return@post
                                    liveVersions[messageId] = version; liveKinds[messageId] = data.optString("kind")
                                    pendingLive.remove(messageId); liveRecoveryDelay = initialLiveRecoveryDelay
                                    while (liveVersions.size > 500) { val first = liveVersions.keys.first(); liveVersions.remove(first); liveKinds.remove(first) }
                                    // The card and its action become available together.
                                    data.optJSONObject("watch")?.let { onWatchState?.invoke(it) }
                                    if (!message.optBoolean("deleted") && message.opt("is_admin") !is Boolean) {
                                        val revision = roomRevision
                                        val overlay = data.optBoolean("overlay_live")
                                        request("rooms/${message.optString("room_id")}/messages/$messageId") { canonical, _ ->
                                            if (roomRevision != revision || liveVersions[messageId] != version || liveKinds[messageId] == "deleted") return@request
                                            if (canonical == null) onChanged("created", messageId)
                                            else {
                                                onMessage?.invoke(canonical)
                                                if (overlay && !canonical.optBoolean("deleted")) onOverlayMessage?.invoke(canonical)
                                            }
                                        }
                                        return@post
                                    }
                                    onMessage?.invoke(message)
                                    if (data.optBoolean("overlay_live") && !message.optBoolean("deleted")) onOverlayMessage?.invoke(message)
                                }
                                Unit
                            }
                            "chat.changed", "chat.badge" -> {
                                if (unreadChannels != null) {
                                    val target = event.optString("channel")
                                    val logical = unreadPhysical.entries.firstOrNull { it.value == target }?.key
                                    if (logical !in unreadSubscribed || logical !in unreadChannels.orEmpty()) return@post
                                    val data = event.opt("data").let { if (it is JSONObject) it else JSONObject(it?.toString() ?: "{}") }
                                    data.optJSONObject("badge")?.let { onBadgeEvent?.invoke(it) }
                                    onUnreadRoomChange?.invoke(data)
                                    return@post
                                }
                                if (channel == null || event.optString("channel") != channel) return@post
                                val data = event.opt("data").let { if (it is JSONObject) it else JSONObject(it?.toString() ?: "{}") }
                                val kind = data.getString("kind")
                                if (kind == "typing" && feedSubscribed && onTyping != null && data.optBoolean("typing_feed")) return@post
                                data.optJSONObject("room_invite")?.let { onRoomInviteState?.invoke(it) }
                                val watch = data.optJSONObject("watch")
                                if (watch != null) onWatchState?.invoke(watch)
                                if (watch != null && onWatchState != null && kind in listOf("watch_control", "watch_status")) return@post
                                val messageId = data.optString("message_id").takeIf { it != "null" && it.isNotEmpty() }
                                if (kind == "deleted" && messageId != null) liveKinds[messageId] = "deleted"
                                if (feedSubscribed && kind == "created" && messageId != null) {
                                    if (liveKinds[messageId] != kind && liveKinds[messageId] != "deleted") {
                                        pendingLive[messageId] = kind
                                        if (pendingLive.size == 1) { main.removeCallbacks(liveRecovery); main.postDelayed(liveRecovery, liveRecoveryDelay) }
                                        while (pendingLive.size > 500) pendingLive.remove(pendingLive.keys.first())
                                    }
                                    return@post
                                }
                                onChanged(kind, messageId)
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
        unreadSent.clear(); unreadSubscribed.clear(); unreadPhysical.clear(); unreadExpiry.clear(); subscriptionPending = false; subscriptionExpiry = 0
        isConnected = false
        onConnection(false)
        val delay = (1000L shl retry.coerceAtMost(5)) + (0..500).random()
        retry++
        main.postDelayed({ if (!closed && generation == waiting) connect() }, delay)
    }

    override fun close() {
        if (closed) return
        finishVisibility()
        stopFirebase()
        isConnected = false
        closed = true; generation++; roomRevision++; main.removeCallbacksAndMessages(null)
        channel = null; socketId = null; room = null
        socket?.cancel(); pendingCall?.cancel(); executor.shutdownNow()

    }
}
