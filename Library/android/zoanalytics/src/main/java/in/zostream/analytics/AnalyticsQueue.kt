package `in`.zostream.analytics

import android.content.Context
import org.json.JSONArray
import org.json.JSONObject
import java.io.File
import kotlin.math.min
import kotlin.random.Random

internal class AnalyticsQueue(
    context: Context,
    private val maxPendingSessions: Int,
    retentionDays: Int,
) {
    private val file = File(context.filesDir, "zoanalytics/queue-v1.json")
    private val retentionMs = retentionDays * 86_400_000L
    private var root = load()

    @Synchronized
    fun upsertPlayback(summary: PlaybackSummary, ownerKey: String, now: Long = System.currentTimeMillis()) {
        prune(now)
        val values = playbackArray()
        var found = -1
        for (index in 0 until values.length()) {
            val item = values.getJSONObject(index)
            if (item.optString("owner_key") == ownerKey &&
                item.optString("session_id") == summary.sessionId
            ) {
                found = index
                break
            }
        }
        val pending = JSONObject()
            .put("owner_key", ownerKey)
            .put("session_id", summary.sessionId)
            .put("revision", summary.revision)
            .put("state", summary.state.wireValue)
            .put("payload", summary.payload)
            .put("queued_at", now)
            .put("attempt_count", 0)
            .put("next_attempt_at", now)
        if (found >= 0) {
            if (values.getJSONObject(found).optInt("revision") <= summary.revision) values.put(found, pending)
        } else values.put(pending)
        trimOldest(values, maxPendingSessions)
        persist()
    }

    @Synchronized
    fun enqueueEvent(
        event: ProductEvent,
        context: AnalyticsContext,
        ownerKey: String,
        now: Long = System.currentTimeMillis(),
    ) {
        prune(now)
        val values = eventArray()
        for (index in 0 until values.length()) {
            if (values.getJSONObject(index).optString("event_id") == event.eventId) return
        }
        values.put(JSONObject()
            .put("owner_key", ownerKey)
            .put("event_id", event.eventId)
            .put("event", event.toJson())
            .put("context", context.toJson())
            .put("queued_at", now)
            .put("attempt_count", 0)
            .put("next_attempt_at", now))
        trimOldest(values, maxPendingSessions * 10)
        persist()
    }

    @Synchronized
    fun duePlayback(ownerKey: String, limit: Int, now: Long = System.currentTimeMillis()): List<JSONObject> =
        due(playbackArray(), ownerKey, limit, now)

    @Synchronized
    fun dueEvents(ownerKey: String, limit: Int, now: Long = System.currentTimeMillis()): List<JSONObject> =
        due(eventArray(), ownerKey, limit, now)

    @Synchronized
    fun removePlayback(ownerKey: String, sessionIds: Set<String>) {
        root.put("playback", filtered(playbackArray()) {
            !(it.optString("owner_key") == ownerKey && sessionIds.contains(it.optString("session_id")))
        })
        persist()
    }

    @Synchronized
    fun removeEvents(ownerKey: String, eventIds: Set<String>) {
        root.put("events", filtered(eventArray()) {
            !(it.optString("owner_key") == ownerKey && eventIds.contains(it.optString("event_id")))
        })
        persist()
    }

    @Synchronized
    fun deferPlayback(ownerKey: String, sessionIds: Set<String>, now: Long = System.currentTimeMillis()) {
        defer(playbackArray(), ownerKey, "session_id", sessionIds, now)
        persist()
    }

    @Synchronized
    fun deferEvents(ownerKey: String, eventIds: Set<String>, now: Long = System.currentTimeMillis()) {
        defer(eventArray(), ownerKey, "event_id", eventIds, now)
        persist()
    }

    @Synchronized
    fun clear(ownerKey: String) {
        root.put("playback", filtered(playbackArray()) { it.optString("owner_key") != ownerKey })
        root.put("events", filtered(eventArray()) { it.optString("owner_key") != ownerKey })
        persist()
    }

    @Synchronized
    fun counts(ownerKey: String): Pair<Int, Int> =
        count(playbackArray(), ownerKey) to count(eventArray(), ownerKey)

    private fun playbackArray(): JSONArray = root.optJSONArray("playback")
        ?: JSONArray().also { root.put("playback", it) }

    private fun eventArray(): JSONArray = root.optJSONArray("events")
        ?: JSONArray().also { root.put("events", it) }

    private fun due(values: JSONArray, ownerKey: String, limit: Int, now: Long): List<JSONObject> {
        val result = mutableListOf<JSONObject>()
        for (index in 0 until values.length()) {
            val item = values.getJSONObject(index)
            if (item.optString("owner_key") == ownerKey && item.optLong("next_attempt_at") <= now) {
                result += item.copyObject()
                if (result.size == limit) break
            }
        }
        return result.sortedBy { it.optLong("queued_at") }
    }

    private fun defer(
        values: JSONArray,
        ownerKey: String,
        idKey: String,
        ids: Set<String>,
        now: Long,
    ) {
        for (index in 0 until values.length()) {
            val item = values.getJSONObject(index)
            if (item.optString("owner_key") == ownerKey && ids.contains(item.optString(idKey))) {
                val attempts = item.optInt("attempt_count") + 1
                item.put("attempt_count", attempts)
                item.put("next_attempt_at", now + retryDelay(attempts))
            }
        }
    }

    private fun retryDelay(attempt: Int): Long {
        val delays = longArrayOf(30_000, 120_000, 600_000, 3_600_000, 21_600_000)
        val base = delays[min(delays.lastIndex, maxOf(0, attempt - 1))]
        return base + Random.nextLong(0, maxOf(1, base / 5))
    }

    private fun prune(now: Long) {
        val cutoff = now - retentionMs
        root.put("playback", filtered(playbackArray()) { it.optLong("queued_at") >= cutoff })
        root.put("events", filtered(eventArray()) { it.optLong("queued_at") >= cutoff })
    }

    private fun trimOldest(values: JSONArray, maximum: Int) {
        while (values.length() > maximum) values.remove(0)
    }

    private fun filtered(values: JSONArray, keep: (JSONObject) -> Boolean): JSONArray {
        val result = JSONArray()
        for (index in 0 until values.length()) {
            values.getJSONObject(index).takeIf(keep)?.let(result::put)
        }
        return result
    }

    private fun count(values: JSONArray, ownerKey: String): Int {
        var total = 0
        for (index in 0 until values.length()) {
            if (values.getJSONObject(index).optString("owner_key") == ownerKey) total++
        }
        return total
    }

    private fun persist() {
        file.parentFile?.mkdirs()
        val temporary = File(file.parentFile, "${file.name}.tmp")
        temporary.writeText(root.toString())
        if (!temporary.renameTo(file)) {
            file.writeText(root.toString())
            temporary.delete()
        }
    }

    private fun load(): JSONObject = runCatching {
        if (file.exists()) JSONObject(file.readText()) else JSONObject()
    }.getOrDefault(JSONObject())
}

