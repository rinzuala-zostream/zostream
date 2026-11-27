package `in`.zostream.analytics

import android.os.Handler
import android.os.Looper
import org.json.JSONArray
import org.json.JSONObject
import java.io.Closeable

/** Native Firebase adapters live in the mobile apps, keeping the reusable client SDK independent. */
interface ChatFirebaseSource {
    fun authenticate(owner: String, loadToken: ((JSONObject?) -> Unit) -> Unit, ready: (String?) -> Unit)
    fun invalidate(owner: String, uid: String)
    fun observe(path: String, limit: Int?, afterCursor: Long?, onValue: (JSONObject) -> Unit, onError: () -> Unit): Closeable
}

internal class ChatFirebaseStream(
    private val source: ChatFirebaseSource,
    private val owner: String,
    private val roomId: String?,
    initialCursor: Long,
    private val active: () -> Boolean,
    private val request: (String, JSONObject?, (JSONObject?, String?) -> Unit) -> Unit,
    private val connection: (Boolean) -> Unit,
    private val changed: (String, String?) -> Unit,
    private val message: (JSONObject, Boolean) -> Unit,
    private val typing: (JSONArray) -> Unit,
) : Closeable {
    private val main = Handler(Looper.getMainLooper())
    private val observations = mutableListOf<Closeable>()
    private var cursor = initialCursor
    private var observedCursor = initialCursor
    private val overlayEvents = mutableSetOf<Long>()
    private var receivedSnapshot = false
    private var accountKeys: Set<String>? = null
    private var roomStateChanged = false
    private val roomEvents = sortedMapOf<Long, JSONObject>()
    private var messages = emptyMap<String, JSONObject>()
    private var needsRecovery = true
    private var disconnectRevision = 0
    private var closed = false
    private var online = false
    private var draining = false
    private var pending = false
    private var generation = 0
    private var retryMs = 1_000L
    private var lastDrainAt = 0L
    private var uid: String? = null
    private val drainTask = Runnable { drain() }
    private val renewTask = Runnable { uid?.let { renew(it, generation) } }
    private val authenticateTask = Runnable { start() }
    val lastCursor get() = cursor

    private fun valid(run: Int) = !closed && generation == run && active()

    fun start() {
        val run = ++generation
        if (!valid(run)) return
        source.authenticate(owner, { result -> request("firebase/token", JSONObject()) { data, _ -> result(data) } }) { user ->
            if (valid(run)) {
                if (user == null) recover() else {
                    uid = user
                    renew(user, run)
                }
            }
        }
    }

    private fun renew(user: String, run: Int) {
        if (!valid(run)) return
        val body = JSONObject()
        roomId?.let { body.put("active_room_id", it) }
        request("firebase/lease", body) { data, error ->
            if (!valid(run)) return@request
            if (data == null) {
                // The API uses HTTP 401 for a missing or revoked Firebase session.
                if (error?.contains("Firebase session", ignoreCase = true) == true) source.invalidate(owner, user)
                recover()
                return@request
            }
            if (roomId != null && !data.optBoolean("room_projection_refreshed")) {
                close()
                changed("access_denied", null)
                return@request
            }
            if (observations.isEmpty()) attach(user, run)
            main.postDelayed(renewTask, data.optLong("refresh_after_seconds", 30).coerceIn(1, 180) * 1_000)
        }
    }

    private fun attach(user: String, run: Int) {
        val denied = {
            if (valid(run)) {
                source.invalidate(owner, user)
                recover()
            }
        }
        val scope = roomId
        val path = if (scope == null) "chat_v1/user_account_events/$user" else "chat_v1/user_sync_events/$user/$scope"
        observations += source.observe(path, 100, cursor.takeIf { scope != null }, { snapshot ->
            if (valid(run)) {
                retryMs = 1_000
                if (scope == null) {
                    val keys = snapshot.keys().asSequence().toSet()
                    val previous = accountKeys
                    val updates = if (previous == null) keys.filter { (it.toLongOrNull() ?: 0) > cursor } else keys - previous
                    accountKeys = keys
                    if (updates.any { snapshot.optJSONObject(it)?.optString("kind") == "room_state_invalidated" }) {
                        roomStateChanged = true
                    }
                    if (updates.isNotEmpty()) scheduleDrain()
                    return@observe
                }
                var newest = cursor
                snapshot.keys().forEach { key ->
                    key.toLongOrNull()?.let { event ->
                        val payload = snapshot.optJSONObject(key) ?: return@let
                        if (event > cursor) roomEvents[event] = payload
                        newest = maxOf(newest, event)
                        if (event > observedCursor && online && receivedSnapshot && payload.optBoolean("overlay_live")) {
                            overlayEvents.add(event)
                        }
                    }
                }
                while (roomEvents.size > 100) {
                    roomEvents.remove(roomEvents.firstKey())
                    needsRecovery = true
                }
                receivedSnapshot = true
                if (newest > observedCursor) {
                    observedCursor = newest
                    scheduleDrain()
                }
            }
        }, denied)
        observations += source.observe(".info/connected", null, null, { value ->
            if (valid(run)) {
                val connected = value.optBoolean("value")
                val changedConnection = online != connected
                online = connected
                if (changedConnection) {
                    connection(connected)
                    if (connected) scheduleDrain() else {
                        disconnectRevision++
                        overlayEvents.clear()
                        receivedSnapshot = false
                        needsRecovery = true
                        if (scope == null) roomStateChanged = true
                        typing(JSONArray())
                    }
                }
            }
        }, denied)
        if (scope != null) observations += source.observe("chat_v1/user_messages/$user/$scope", 30, cursor, { snapshot ->
            if (valid(run)) messages = snapshot.keys().asSequence().mapNotNull { key ->
                snapshot.optJSONObject(key)?.let { it.optString("id") to it }
            }.toMap()
        }, denied)
        if (scope != null) observations += source.observe("chat_v1/user_typing/$user/$scope", 20, null, { snapshot ->
            if (valid(run)) {
                val users = JSONArray()
                val now = System.currentTimeMillis()
                snapshot.keys().forEach { key ->
                    val item = snapshot.optJSONObject(key) ?: return@forEach
                    val remaining = item.optLong("expires_at") - now
                    if (remaining > 0) users.put(JSONObject().put("user_id", key).put("name", item.optString("name", "Viewer"))
                        .put("is_admin", item.optBoolean("is_admin")).put("expires_in", (remaining + 999) / 1_000))
                }
                typing(users)
            }
        }, denied)
    }

    fun updateAccountCursor(value: Long) {
        if (roomId == null) cursor = maxOf(cursor, value)
    }

    private fun scheduleDrain() {
        pending = true
        if (draining) return
        main.removeCallbacks(drainTask)
        val sinceLast = android.os.SystemClock.elapsedRealtime() - lastDrainAt
        main.postDelayed(drainTask, maxOf(300, 1_000 - sinceLast))
    }

    private fun drain() {
        val run = generation
        if (!valid(run) || !online || !pending || draining) return
        pending = false
        lastDrainAt = android.os.SystemClock.elapsedRealtime()
        val scope = roomId
        if (scope == null) {
            val kind = if (roomStateChanged) "room_state_invalidated" else "unread_invalidated"
            roomStateChanged = false
            changed(kind, null)
            return
        }
        if (!needsRecovery) {
            val events = roomEvents.filterKeys { it > cursor }
            for ((eventCursor, event) in events) {
                val kind = event.optString("kind")
                val record = messages[event.optString("message_id")]
                val payload = if (kind == "created" || kind == "deleted") liveMessage(record, eventCursor) else null
                if ((kind == "created" || kind == "deleted") && payload == null) {
                    needsRecovery = true
                    break
                }
                dispatch(event, payload)
                if (!valid(run)) return
                cursor = eventCursor
                roomEvents.remove(eventCursor)
            }
            if (!needsRecovery) return
        }
        val recoveryStartedAt = disconnectRevision
        draining = true
        request("rooms/$scope/sync?cursor=$cursor", null) { data, _ ->
            if (valid(run)) {
                draining = false
                if (data == null) {
                    pending = true
                    main.postDelayed(drainTask, retryMs)
                    retryMs = (retryMs * 2).coerceAtMost(30_000)
                    return@request
                }
                retryMs = 1_000
                val next = data.optLong("next_cursor", cursor)
                if (data.optBoolean("cursor_expired")) changed("reconcile", null)
                val events = data.optJSONArray("events") ?: JSONArray()
                for (index in 0 until events.length()) {
                    val event = events.getJSONObject(index)
                    dispatch(event, event.optJSONObject("message"))
                    if (!valid(run)) return@request
                }
                cursor = next
                overlayEvents.removeAll { it <= cursor }
                roomEvents.keys.removeAll { it <= cursor }
                needsRecovery = data.optBoolean("has_more") || recoveryStartedAt != disconnectRevision
                if (data.optBoolean("has_more") || pending) scheduleDrain()
            }
        }
    }

    private fun liveMessage(record: JSONObject?, eventCursor: Long): JSONObject? {
        if (record == null || record.optLong("cursor") != eventCursor || !record.isNull("reply_to")
            || !record.has("sender_id") || !record.has("created_at_iso")) return null
        return JSONObject().put("id", record.getString("id")).put("room_id", record.getString("room_id"))
            .put("user_id", record.getString("sender_id")).put("display_name", record.getString("sender_name"))
            .put("is_admin", record.optBoolean("sender_is_admin")).put("request_id", record.getString("client_op_id"))
            .put("body", record.opt("body")).put("deleted", record.optBoolean("deleted")).put("spoiler", record.optBoolean("spoiler"))
            .put("created_at", record.getString("created_at_iso")).put("revision", record.optLong("revision", 1))
            .put("movie_suggestion", record.optJSONObject("suggestion")).put("room_invite", record.optJSONObject("invitation"))
            .put("watch_invite", record.optJSONObject("watch_invite")).put("moment", record.optJSONObject("moment"))
    }

    private fun dispatch(event: JSONObject, payload: JSONObject?) {
        val kind = event.optString("kind")
        val id = event.optString("message_id").takeIf { it.toLongOrNull() != null }
        when {
            kind == "created" && payload != null -> message(payload, overlayEvents.remove(event.optLong("cursor")))
            kind == "deleted" && payload != null -> message(payload, false)
            kind == "room_invite_status" -> changed("sync", id)
            kind != "read" -> changed(kind, id)
        }
    }

    private fun recover() {
        reset()
        if (closed || !active()) return
        connection(false)
        main.postDelayed(authenticateTask, retryMs)
        retryMs = (retryMs * 2).coerceAtMost(30_000)
    }

    private fun reset() {
        generation++
        observations.forEach { it.close() }
        observations.clear()
        main.removeCallbacksAndMessages(null)
        online = false; pending = false; draining = false
        overlayEvents.clear()
        receivedSnapshot = false
        accountKeys = null
        roomEvents.clear(); messages = emptyMap(); needsRecovery = true
    }

    override fun close() {
        closed = true
        reset()
    }
}
