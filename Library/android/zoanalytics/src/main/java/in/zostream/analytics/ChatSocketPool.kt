package `in`.zostream.analytics

import android.os.Handler
import android.os.Looper
import okhttp3.*
import okio.ByteString
import org.json.JSONObject

/** Logical chat clients retain their own room/token context, but share one wire
 * connection per API transport, signed-in device and WebSocket endpoint. */
internal object ChatSocketPool {
    private val main = Handler(Looper.getMainLooper())
    private val hubs = mutableMapOf<String, Hub>()

    fun open(http: OkHttpClient, request: Request, owner: String, listener: WebSocketListener): WebSocket {
        val key = "${System.identityHashCode(http)}:$owner:${request.url}:${request.header("Origin")}"
        val hub = hubs[key] ?: Hub(key, http, request).also { hubs[key] = it }
        return hub.attach(listener)
    }

    private class Hub(val key: String, val http: OkHttpClient, val request: Request) {
        private val members = linkedSetOf<Lease>()
        private val channels = mutableMapOf<String, MutableSet<Lease>>()
        private val subscribed = mutableMapOf<String, String>()
        private var wire: WebSocket? = null
        private var handshake: String? = null
        private var lastPing = Long.MIN_VALUE / 2
        private var dead = false

        fun attach(listener: WebSocketListener): Lease {
            val lease = Lease(this, listener)
            members.add(lease)
            handshake?.let { message -> main.post { if (lease in members) listener.onMessage(lease, message) } }
            if (wire == null) wire = http.newWebSocket(request, object : WebSocketListener() {
                override fun onMessage(webSocket: WebSocket, text: String) { main.post {
                    if (dead) return@post
                    val frame = runCatching { JSONObject(text) }.getOrNull()
                    when (frame?.optString("event")) {
                        "pusher:connection_established" -> handshake = text
                        "pusher_internal:subscription_succeeded" -> subscribed[frame.optString("channel")] = text
                    }
                    members.toList().forEach { it.listener.onMessage(it, text) }
                } }
                override fun onFailure(webSocket: WebSocket, t: Throwable, response: Response?) { main.post {
                    fail { it.listener.onFailure(it, t, response) }
                } }
                override fun onClosing(webSocket: WebSocket, code: Int, reason: String) { webSocket.close(code, reason) }
                override fun onClosed(webSocket: WebSocket, code: Int, reason: String) { main.post {
                    fail { it.listener.onClosed(it, code, reason) }
                } }
            })
            return lease
        }

        fun send(lease: Lease, text: String): Boolean {
            if (dead || lease !in members) return false
            val frame = runCatching { JSONObject(text) }.getOrNull()
            val event = frame?.optString("event")
            val channel = frame?.optJSONObject("data")?.optString("channel").orEmpty()
            if (event == "pusher:subscribe" && channel.isNotEmpty()) {
                val refs = channels.getOrPut(channel) { linkedSetOf() }
                if (!refs.add(lease)) return true
                if (refs.size > 1) {
                    subscribed[channel]?.let { message -> main.post { if (lease in members) lease.listener.onMessage(lease, message) } }
                    return true
                }
            } else if (event == "pusher:unsubscribe" && channel.isNotEmpty()) {
                return unsubscribe(lease, channel)
            } else if (event == "pusher:ping") {
                val now = android.os.SystemClock.elapsedRealtime()
                if (now - lastPing < 25_000) return true
                lastPing = now
            }
            return wire?.send(text) == true
        }

        private fun unsubscribe(lease: Lease, channel: String): Boolean {
            val refs = channels[channel] ?: return true
            refs.remove(lease)
            if (refs.isNotEmpty()) return true
            channels.remove(channel); subscribed.remove(channel)
            return wire?.send(JSONObject().put("event", "pusher:unsubscribe").put("data", JSONObject().put("channel", channel)).toString()) == true
        }

        fun release(lease: Lease) {
            if (!members.remove(lease)) return
            channels.keys.toList().forEach { unsubscribe(lease, it) }
            if (members.isEmpty()) {
                dead = true
                if (hubs[key] === this) hubs.remove(key)
                wire?.cancel()
            }
        }

        private fun fail(callback: (Lease) -> Unit) {
            if (dead) return
            dead = true
            if (hubs[key] === this) hubs.remove(key)
            val clients = members.toList(); members.clear(); channels.clear(); subscribed.clear()
            clients.forEach(callback)
        }

        fun queued() = wire?.queueSize() ?: 0L
    }

    private class Lease(val hub: Hub, val listener: WebSocketListener) : WebSocket {
        override fun request() = hub.request
        override fun queueSize() = hub.queued()
        override fun send(text: String) = hub.send(this, text)
        override fun send(bytes: ByteString) = false
        override fun close(code: Int, reason: String?): Boolean { hub.release(this); return true }
        override fun cancel() { hub.release(this) }
    }
}
