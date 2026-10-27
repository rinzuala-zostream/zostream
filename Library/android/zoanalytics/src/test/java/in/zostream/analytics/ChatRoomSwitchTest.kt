package `in`.zostream.analytics

import android.os.Looper
import okhttp3.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.ResponseBody.Companion.toResponseBody
import okio.ByteString
import org.json.JSONObject
import org.junit.Assert.*
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.Shadows.shadowOf
import org.robolectric.annotation.Config
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicInteger
import java.util.concurrent.atomic.AtomicReference

@RunWith(RobolectricTestRunner::class)
@Config(sdk = [28])
class ChatRoomSwitchTest {
    @Test fun queuedMovieJoinKeepsThePlaybackTokenFromItsOwnRequest() {
        val entered = CountDownLatch(1)
        val release = CountDownLatch(1)
        var playbackToken: String? = "movie-token"
        val movieHeader = AtomicReference<String?>()
        val http = OkHttpClient.Builder().addInterceptor { chain ->
            val request = chain.request()
            val data = if (request.url.encodedPath.endsWith("/unread")) {
                entered.countDown()
                check(release.await(2, TimeUnit.SECONDS))
                JSONObject().put("rooms", org.json.JSONArray())
            } else {
                movieHeader.set(request.header("X-Playback-Token"))
                JSONObject().put("room_id", "movie").put("channel", "private-chat.movie").put("websocket_url", "")
            }
            Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(200).message("OK")
                .body(JSONObject().put("success", true).put("data", data).toString().toResponseBody("application/json".toMediaType())).build()
        }.build()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "alice") }, "test",
            playbackToken = { playbackToken }, onChanged = { _, _ -> }, onConnection = {}, http = http)
        var joined = false
        try {
            client.request("unread") { _, error -> assertNull(error) }
            assertTrue(entered.await(2, TimeUnit.SECONDS))
            client.join("movie", "42") { room, error -> assertNull(error); joined = room != null }
            // A subsequent tab change must not strip authorization from the queued movie join.
            playbackToken = null
            release.countDown()
            await { joined }
            assertEquals("movie-token", movieHeader.get())
        } finally { release.countDown(); client.close() }
    }

    @Test fun accountChangeDiscardsOldResponsesAndDoesNotReplayQueuedWrites() {
        val owner = AtomicReference("alice")
        val entered = CountDownLatch(1)
        val release = CountDownLatch(1)
        val calls = AtomicInteger()
        val http = OkHttpClient.Builder().addInterceptor { chain ->
            if (calls.incrementAndGet() == 1) {
                entered.countDown()
                check(release.await(2, TimeUnit.SECONDS))
            }
            Response.Builder().request(chain.request()).protocol(Protocol.HTTP_1_1).code(200).message("OK")
                .body("""{"success":true,"data":{"rooms":[]}}""".toResponseBody("application/json".toMediaType())).build()
        }.build()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", owner.get()) }, "test",
            onChanged = { _, _ -> }, onConnection = {}, http = http)
        var oldCallback = false
        var completed = false
        try {
            client.request("invite-rooms") { _, _ -> oldCallback = true }
            assertTrue(entered.await(2, TimeUnit.SECONDS))
            client.request("invite-rooms", JSONObject()) { _, _ -> oldCallback = true }
            owner.set("bob")
            release.countDown()
            client.request("invite-rooms") { data, error -> assertNull(error); completed = data != null }
            await { completed }
            assertFalse(oldCallback)
            assertEquals(2, calls.get())
        } finally { release.countDown(); client.close() }
    }

    @Test fun malformedResponseShowsReadableError() {
        val http = OkHttpClient.Builder().addInterceptor { chain ->
            Response.Builder().request(chain.request()).protocol(Protocol.HTTP_1_1).code(502).message("Bad gateway")
                .body("<html>Gateway unavailable</html>".toResponseBody("text/html".toMediaType())).build()
        }.build()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "alice") }, "test",
            onChanged = { _, _ -> }, onConnection = {}, http = http)
        var error: String? = null
        try {
            client.request("invites") { data, failure -> assertNull(data); error = failure }
            await { error != null }
            assertEquals("Chat returned an unreadable response. Please try again.", error)
        } finally { client.close() }
    }

    private class Socket(private val original: Request, val listener: WebSocketListener) : WebSocket {
        val events = mutableListOf<JSONObject>()
        var cancellations = 0
        override fun request() = original
        override fun queueSize() = 0L
        override fun send(bytes: ByteString) = false
        override fun send(text: String): Boolean {
            val event = JSONObject(text)
            events.add(event)
            if (event.optString("event") == "pusher:subscribe") {
                listener.onMessage(this, JSONObject().put("event", "pusher_internal:subscription_succeeded")
                    .put("channel", event.getJSONObject("data").getString("channel")).put("data", JSONObject()).toString())
            }
            return true
        }
        override fun close(code: Int, reason: String?) = true
        override fun cancel() { cancellations++ }
        fun emit(channel: String) {
            listener.onMessage(this, JSONObject().put("event", "chat.changed").put("channel", channel)
                .put("data", JSONObject().put("kind", "created").put("message_id", "9")).toString())
        }
    }

    private class Http : OkHttpClient() {
        private val delegate = Builder().addInterceptor { chain ->
        val request = chain.request()
        val data = if (request.url.encodedPath.endsWith("/unread/auth")) {
            val buffer = okio.Buffer(); request.body!!.writeTo(buffer)
            JSONObject().put("auth", "signed").put("channel", JSONObject(buffer.readUtf8()).getString("channel"))
        } else if (request.url.encodedPath.endsWith("/auth")) {
            val id = request.url.pathSegments[4]
            JSONObject().put("auth", "signed").put("channel", "private-chat.$id")
        } else {
            val buffer = okio.Buffer(); request.body!!.writeTo(buffer)
            val body = JSONObject(buffer.readUtf8())
            val id = if (body.optString("type") == "global") "global" else "movie"
            JSONObject().put("room_id", id).put("channel", "private-chat.$id")
                .put("websocket_url", "wss://example.test/app/key")
        }
        Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(200).message("OK")
            .body(JSONObject().put("success", true).put("data", data).toString().toResponseBody("application/json".toMediaType())).build()
        }.build()
        override fun newCall(request: Request): Call = delegate.newCall(request)
        val sockets = mutableListOf<Socket>()
        override fun newWebSocket(request: Request, listener: WebSocketListener): WebSocket {
            val socket = Socket(request, listener); sockets.add(socket)
            listener.onMessage(socket, """{"event":"pusher:connection_established","data":{"socket_id":"1.2"}}""")
            return socket
        }
    }

    private fun await(condition: () -> Boolean) {
        repeat(200) {
            shadowOf(Looper.getMainLooper()).idle()
            if (condition()) return
            Thread.sleep(5)
        }
        fail("Chat operation did not finish")
    }

    @Test fun switchingRoomsReusesSocketAndRejectsOldChannelEvents() {
        val http = Http()
        var live = 0
        var changes = 0
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { kind, _ -> if (kind == "created") changes++ }, onConnection = { if (it) live++ }, http = http)
        try {
            client.join("movie", "42") { _, error -> assertNull(error) }
            await { live == 1 }
            val socket = http.sockets.single()
            client.join("global", null) { _, error -> assertNull(error) }
            await { live == 2 }
            assertEquals(1, http.sockets.size)
            assertEquals(0, socket.cancellations)
            assertTrue(socket.events.any { it.optString("event") == "pusher:unsubscribe" && it.getJSONObject("data").optString("channel") == "private-chat.movie" })
            socket.emit("private-chat.movie")
            socket.emit("private-chat.global")
            shadowOf(Looper.getMainLooper()).idle()
            assertEquals(1, changes)
            client.join("movie", "42") { _, error -> assertNull(error) }
            await { live == 3 }
            assertEquals(1, http.sockets.size)
        } finally { client.close() }
        assertEquals(1, http.sockets.single().cancellations)
    }

    @Test fun watchFramesDeliverStateDirectlyAndLiveSocketDoesNotReconcileByPolling() {
        val http = Http()
        var syncs = 0
        var watchInvalidations = 0
        val states = mutableListOf<JSONObject>()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { kind, _ -> if (kind == "sync") syncs++ else if (kind == "watch_control") watchInvalidations++ },
            onConnection = {}, http = http)
        client.onWatchState = { states.add(it) }
        try {
            client.join("movie", "42") { _, error -> assertNull(error) }
            await { client.isConnected }
            val socket = http.sockets.single()
            val watch = JSONObject().put("room_id", "movie").put("session_id", "7").put("event_id", "12")
                .put("joined_count", 2).put("streaming_count", 1)
                .put("playback", JSONObject().put("position_ms", 42000).put("paused", true).put("revision", "3"))
            fun emit(channel: String) = socket.listener.onMessage(socket, JSONObject().put("event", "chat.changed")
                .put("channel", channel).put("data", JSONObject().put("kind", "watch_control").put("watch", watch)).toString())
            emit("private-chat.old")
            emit("private-chat.movie")
            shadowOf(Looper.getMainLooper()).idle()
            assertEquals(1, states.size)
            assertEquals(42000, states.single().getJSONObject("playback").getInt("position_ms"))
            assertEquals(0, watchInvalidations)
            assertEquals(1, syncs)
            shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofSeconds(30))
            assertEquals(1, syncs)
            assertTrue(socket.events.any { it.optString("event") == "pusher:ping" })
        } finally { client.close() }
    }

    @Test fun badgeSubscriptionsShareOneSocketAndRejectUnsubscribedChannels() {
        val http = Http()
        var syncs = 0
        val badges = mutableListOf<JSONObject>()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { kind, _ -> if (kind == "sync") syncs++ }, onConnection = {}, http = http)
        client.onBadgeEvent = { badges.add(it) }
        val summary = JSONObject().put("websocket_url", "wss://example.test/app/key")
            .put("channels", org.json.JSONArray().put("private-chat.1").put("private-chat-user-own"))
        try {
            client.configureUnread(summary)
            await { client.isConnected }
            assertEquals(1, syncs)
            val socket = http.sockets.single()
            fun emit(channel: String) = socket.listener.onMessage(socket, JSONObject().put("event", "chat.badge")
                .put("channel", channel).put("data", JSONObject().put("badge", JSONObject().put("kind", "read"))).toString())
            emit("private-chat-user-other")
            emit("private-chat-user-own")
            emit("private-chat.1")
            shadowOf(Looper.getMainLooper()).idle()
            assertEquals(2, badges.size)
            client.configureUnread(summary)
            shadowOf(Looper.getMainLooper()).idle()
            assertEquals(1, http.sockets.size)
            assertEquals(2, socket.events.count { it.optString("event") == "pusher:subscribe" })
            client.configureUnread(JSONObject(summary.toString()).put("channels", org.json.JSONArray().put("private-chat-user-own")))
            emit("private-chat.1")
            shadowOf(Looper.getMainLooper()).idle()
            assertEquals(2, badges.size)
            assertTrue(socket.events.any { it.optString("event") == "pusher:unsubscribe" })
        } finally { client.close() }
    }

    @Test fun directRoomListAndEndResponsesAreRecognized() {
        var responseBody = """{"rooms":[]}"""
        val http = OkHttpClient.Builder().addInterceptor { chain ->
            Response.Builder().request(chain.request()).protocol(Protocol.HTTP_1_1).code(200).message("OK")
                .body(responseBody.toResponseBody("application/json".toMediaType())).build()
        }.build()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { _, _ -> }, onConnection = {}, http = http)
        try {
            var response: JSONObject? = null
            client.request("invite-rooms") { data, error -> assertNull(error); response = data }
            await { response != null }
            assertEquals(0, response!!.getJSONArray("rooms").length())
            response = null
            responseBody = """{"ended":true}"""
            client.request("invite-rooms/1/end", JSONObject()) { data, error -> assertNull(error); response = data }
            await { response != null }
            assertTrue(response!!.getBoolean("ended"))
        } finally { client.close() }
    }
}
