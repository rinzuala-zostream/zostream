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
    @Test fun visibleTranscriptPublishesHiddenStateOnSwitchAndCloseWithoutStoppingTheSocketEarly() {
        val reports = java.util.Collections.synchronizedList(mutableListOf<Pair<String, JSONObject>>())
        val http = OkHttpClient.Builder().addInterceptor { chain ->
            val request = chain.request()
            val path = request.url.encodedPath
            val data = if (path.endsWith("/heartbeat")) {
                val buffer = okio.Buffer(); request.body!!.writeTo(buffer)
                reports.add(path to JSONObject(buffer.readUtf8()))
                JSONObject().put("online_count", 1)
            } else {
                val id = if (path.contains("invite-rooms/2")) "2" else "1"
                JSONObject().put("room_id", id).put("channel", "private-chat.$id").put("websocket_url", "")
            }
            Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(200).message("OK")
                .body(JSONObject().put("success", true).put("data", data).toString().toResponseBody("application/json".toMediaType())).build()
        }.build()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "alice") }, "test",
            onChanged = { _, _ -> }, onConnection = {}, http = http)
        fun snapshot() = synchronized(reports) { reports.toList() }
        try {
            client.setRoomVisible(true)
            var joined = false
            client.join("global", null) { _, error -> assertNull(error); joined = true }
            await { joined && snapshot().size == 1 }
            joined = false
            client.join("invite", "2") { _, error -> assertNull(error); joined = true }
            await { joined && snapshot().size == 3 }
            client.close()
            await { snapshot().size == 4 }
            for (id in listOf("1", "2")) {
                val states = snapshot().filter { it.first.endsWith("/$id/heartbeat") }.map { it.second }
                    .sortedBy { it.getLong("visibility_sequence") }
                assertEquals(2, states.size)
                assertTrue(states[0].getBoolean("visible"))
                assertFalse(states[1].getBoolean("visible"))
                assertTrue(states[1].getLong("visibility_sequence") > states[0].getLong("visibility_sequence"))
            }
        } finally { client.close() }
    }

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

    private class Socket(private val original: Request, val listener: WebSocketListener, private val acknowledgePresence: Boolean = false) : WebSocket {
        val events = mutableListOf<JSONObject>()
        var cancellations = 0
        override fun request() = original
        override fun queueSize() = 0L
        override fun send(bytes: ByteString) = false
        override fun send(text: String): Boolean {
            val event = JSONObject(text)
            events.add(event)
            if (event.optString("event") == "chat:presence" && acknowledgePresence) {
                listener.onMessage(this, JSONObject().put("event", "chat:presence_ack").put("data", event.getJSONObject("data")).toString())
            }
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
        var lease = ""
        var protocolV2 = false
        val paths = java.util.concurrent.CopyOnWriteArrayList<String>()
        var expiresIn = 300
        var denyPresence = false
        var establishConnections = true
        private val delegate = Builder().addInterceptor { chain ->
        val request = chain.request()
        paths.add(request.url.encodedPath)
        val data = if (request.url.encodedPath.endsWith("/unread/auth-batch")) {
            val buffer = okio.Buffer(); request.body!!.writeTo(buffer)
            val requested = JSONObject(buffer.readUtf8()).getJSONArray("channels")
            val results = JSONObject()
            for (i in 0 until requested.length()) {
                val target = requested.getString(i)
                results.put(target, JSONObject().put("auth", "signed").put("channel", target + lease).put("expires_in", expiresIn))
            }
            JSONObject().put("authorizations", results)
        } else if (request.url.encodedPath.endsWith("/unread/auth")) {
            val buffer = okio.Buffer(); request.body!!.writeTo(buffer)
            JSONObject().put("auth", "signed").put("channel", JSONObject(buffer.readUtf8()).getString("channel") + lease).put("expires_in", expiresIn)
        } else if (request.url.encodedPath.endsWith("/auth")) {
            val id = request.url.pathSegments[4]
            JSONObject().put("auth", "signed").put("channel", "private-chat.$id$lease").put("expires_in", expiresIn).apply {
                if (protocolV2) put("control_token", "ticket").put("feed_channel", "private-chat-feed.$id.ticket$lease").put("feed_auth", "signed")
            }
        } else {
            val buffer = okio.Buffer(); request.body!!.writeTo(buffer)
            val body = JSONObject(buffer.readUtf8())
            val id = if (body.optString("type") == "global") "global" else "movie"
            JSONObject().put("room_id", id).put("channel", "private-chat.$id")
                .put("websocket_url", "wss://example.test/app/key")
        }
        Response.Builder().request(request).protocol(Protocol.HTTP_1_1).code(if (denyPresence && request.url.encodedPath.endsWith("/heartbeat")) 403 else 200).message("OK")
            .body(JSONObject().put("success", true).put("data", data).toString().toResponseBody("application/json".toMediaType())).build()
        }.build()
        override fun newCall(request: Request): Call = delegate.newCall(request)
        val sockets = mutableListOf<Socket>()
        override fun newWebSocket(request: Request, listener: WebSocketListener): WebSocket {
            val socket = Socket(request, listener, protocolV2); sockets.add(socket)
            if (establishConnections) listener.onMessage(socket, """{"event":"pusher:connection_established","data":{"socket_id":"1.2"}}""")
            return socket
        }
    }

    @Test fun badgeAndTranscriptShareOneSocketAndClosingOneKeepsTheOtherSubscribed() {
        val http = Http()
        var badges = 0
        val badge = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { _, _ -> }, onConnection = {}, http = http)
        val chat = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { _, _ -> }, onConnection = {}, http = http)
        val other = ZoChatClient("https://example.test", { AnalyticsCredentials("other", "device", "another-owner") }, "test",
            onChanged = { _, _ -> }, onConnection = {}, http = http)
        try {
            badge.onBadgeEvent = { badges++ }
            badge.configureUnread(JSONObject().put("websocket_url", "wss://example.test/app/key").put("channels", org.json.JSONArray(listOf("private-chat.global"))))
            await { badge.isConnected }
            chat.join("global", null) { _, error -> assertNull(error) }
            await { chat.isConnected }
            assertEquals(1, http.sockets.size)
            val socket = http.sockets.single()
            assertEquals(1, socket.events.count { it.optString("event") == "pusher:subscribe" })
            chat.close()
            assertEquals(0, socket.cancellations)
            socket.listener.onMessage(socket, JSONObject().put("event", "chat.changed").put("channel", "private-chat.global")
                .put("data", JSONObject().put("badge", JSONObject().put("kind", "created"))).toString())
            await { badges == 1 }
            other.join("global", null) { _, error -> assertNull(error) }
            await { other.isConnected }
            assertEquals(2, http.sockets.size)
            badge.close(); assertEquals(1, socket.cancellations)
        } finally { badge.close(); chat.close(); other.close() }
    }

    @Test fun socketPresenceAndDirectMessagesAvoidSteadyHttpAndKeepMissedEventRecovery() {
        val http = Http().apply { protocolV2 = true }
        val changes = mutableListOf<String>(); val received = mutableListOf<JSONObject>()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { kind, _ -> changes.add(kind) }, onConnection = {}, http = http)
        try {
            client.onMessage = { received.add(it) }
            client.setRoomVisible(true)
            client.join("global", null) { _, error -> assertNull(error) }
            await { client.isConnected && changes.count { it == "sync" } >= 2 }
            val initial = http.paths.count { it.endsWith("/heartbeat") }
            val socket = http.sockets.single()
            client.setRoomVisible(true)
            shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofSeconds(33))
            assertEquals(initial, http.paths.count { it.endsWith("/heartbeat") })
            assertTrue(socket.events.any { it.optString("event") == "chat:presence" })
            changes.clear()
            socket.emit("private-chat.global")
            val message = JSONObject().put("id", "9").put("room_id", "global").put("body", "Live")
            fun publish(version: String) = socket.listener.onMessage(socket, JSONObject().put("event", "chat.message")
                .put("channel", "private-chat-feed.global.ticket").put("data", JSONObject().put("room_id", "global")
                    .put("event_id", version).put("kind", "created").put("message", message)).toString())
            publish("20"); publish("20"); publish("19")
            shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofSeconds(3))
            assertEquals(1, received.size)
            assertFalse(changes.contains("created"))
            socket.listener.onMessage(socket, JSONObject().put("event", "chat.changed").put("channel", "private-chat.global")
                .put("data", JSONObject().put("kind", "created").put("message_id", "10")).toString())
            shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofSeconds(3))
            assertTrue(changes.contains("created"))
        } finally { client.close() }
    }

    @Test fun badgeChannelsUseOneAuthorizationBatchWhenTheServerAdvertisesSupport() {
        val http = Http()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { _, _ -> }, onConnection = {}, http = http)
        try {
            client.configureUnread(JSONObject().put("auth_batch", true).put("websocket_url", "wss://example.test/app/key")
                .put("channels", org.json.JSONArray(listOf("private-chat.global", "private-chat.movie", "private-chat.user"))))
            await { client.isConnected }
            assertEquals(1, http.paths.count { it.endsWith("/unread/auth-batch") })
            assertEquals(0, http.paths.count { it.endsWith("/unread/auth") })
        } finally { client.close() }
    }

    @Test fun reconnectingTranscriptRecoversWithoutReopeningAndStopsPollingWhenLive() {
        val http = Http().apply { establishConnections = false }
        val changes = mutableListOf<String>()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { kind, _ -> changes.add(kind) }, onConnection = {}, http = http)
        try {
            var joined = false
            client.join("global", null) { _, error -> assertNull(error); joined = true }
            await { joined && http.sockets.size == 1 }
            assertFalse(client.isConnected)
            shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofSeconds(5))
            assertEquals(1, changes.count { it == "poll" })
            shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofSeconds(5))
            assertEquals(1, changes.count { it == "poll" })
            shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofSeconds(7))
            assertEquals(2, changes.count { it == "poll" })
            val socket = http.sockets.single()
            socket.listener.onMessage(socket, """{"event":"pusher:connection_established","data":{"socket_id":"1.2"}}""")
            await { client.isConnected }
            socket.emit("private-chat.global")
            shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofSeconds(5))
            assertEquals(2, changes.count { it == "poll" })
            assertTrue(changes.contains("created"))
            client.close()
            shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofSeconds(5))
            assertEquals(2, changes.count { it == "poll" })
        } finally { client.close() }
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
        val connectionEvents = mutableListOf<String>()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { kind, _ -> connectionEvents.add(kind); if (kind == "created") changes++ }, onConnection = { if (it) live++ }, http = http)
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
            assertEquals(1, connectionEvents.count { it == "sync" })
            assertEquals(2, connectionEvents.count { it == "subscribed" })
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

    @Test fun roomInvitationStateIsDeliveredOnlyFromTheSubscribedPublicChat() {
        val http = Http()
        val states = mutableListOf<JSONObject>()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { _, _ -> }, onConnection = {}, http = http)
        client.onRoomInviteState = { states.add(it) }
        try {
            client.join("global", null) { _, error -> assertNull(error) }
            await { client.isConnected }
            val socket = http.sockets.single()
            fun emit(channel: String) = socket.listener.onMessage(socket, JSONObject().put("event", "chat.changed")
                .put("channel", channel).put("data", JSONObject().put("kind", "room_invite_status")
                    .put("room_invite", JSONObject().put("room_id", "9").put("active", false))).toString())
            emit("private-chat.other")
            emit("private-chat.global")
            shadowOf(Looper.getMainLooper()).idle()
            assertEquals(1, states.size)
            assertEquals("9", states.single().getString("room_id"))
            assertFalse(states.single().getBoolean("active"))
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

    @Test fun connectedTranscriptReconcilesAndRenewsLeasedChannelsWithoutAnotherSocket() {
        val http = Http().apply { lease = ".lease.1"; expiresIn = 30 }
        val changes = mutableListOf<String>()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { kind, _ -> changes.add(kind) }, onConnection = {}, http = http)
        try {
            client.join("global", null) { _, error -> assertNull(error) }
            await { client.isConnected }
            http.lease = ".lease.2"
            shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofSeconds(30))
            await { changes.contains("subscribed") }
            assertEquals(1, changes.count { it == "sync" })
            assertFalse(changes.contains("reconcile"))
            assertEquals(1, http.sockets.size)
            val socket = http.sockets.single()
            assertTrue(socket.events.any { it.optString("event") == "pusher:unsubscribe" && it.getJSONObject("data").optString("channel") == "private-chat.global.lease.1" })
            socket.emit("private-chat.global.lease.1")
            socket.emit("private-chat.global.lease.2")
            shadowOf(Looper.getMainLooper()).idle()
            assertEquals(1, changes.count { it == "created" })
            http.denyPresence = true
            shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofSeconds(30))
            await { changes.contains("access_denied") }
            assertFalse(client.isConnected)
        } finally { client.close() }
    }

    @Test fun badgeLeaseRenewalUsesLogicalAuthorizationAndRejectsOldPhysicalFrames() {
        val http = Http().apply { lease = ".lease.1"; expiresIn = 30 }
        val badges = mutableListOf<JSONObject>()
        var syncs = 0
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { kind, _ -> if (kind == "sync") syncs++ }, onConnection = {}, http = http)
        client.onBadgeEvent = { badges.add(it) }
        try {
            client.configureUnread(JSONObject().put("websocket_url", "wss://example.test/app/key")
                .put("channels", org.json.JSONArray().put("private-chat-user-own")))
            await { client.isConnected }
            http.lease = ".lease.2"
            shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofSeconds(30))
            await { syncs == 2 }
            val socket = http.sockets.single()
            fun emit(channel: String) = socket.listener.onMessage(socket, JSONObject().put("event", "chat.badge")
                .put("channel", channel).put("data", JSONObject().put("badge", JSONObject().put("kind", "read"))).toString())
            emit("private-chat-user-own.lease.1")
            emit("private-chat-user-own.lease.2")
            shadowOf(Looper.getMainLooper()).idle()
            assertEquals(1, badges.size)
            assertEquals(1, http.sockets.size)
        } finally { client.close() }
    }

    @Test fun healthySocketSkipsIdleMaintenanceAndKeepsFiveMinuteIntegrity() {
        val http = Http()
        val changes = mutableListOf<String>()
        val client = ZoChatClient("https://example.test", { AnalyticsCredentials("token", "device", "owner") }, "test",
            onChanged = { kind, _ -> changes.add(kind) }, onConnection = {}, http = http)
        try {
            client.join("global", null) { _, error -> assertNull(error) }
            await { client.isConnected }
            val socket = http.sockets.single()
            repeat(10) { index ->
                socket.listener.onMessage(socket, """{"event":"pusher:pong","data":{}}""")
                shadowOf(Looper.getMainLooper()).idle()
                shadowOf(Looper.getMainLooper()).idleFor(java.time.Duration.ofSeconds(30))
                if (index < 9) assertFalse(changes.contains("reconcile"))
            }
            assertEquals(1, changes.count { it == "reconcile" })
            assertEquals(0, changes.count { it == "maintenance" })
            assertEquals(1, http.sockets.size)
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
