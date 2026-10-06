package `in`.zostream.analytics

import android.content.Context
import android.os.Handler
import android.os.Looper
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors

fun interface AnalyticsCallback {
    fun onComplete(error: Throwable?)
}

fun interface AnalyticsConfigurationCallback {
    fun onComplete(configuration: AnalyticsRemoteConfiguration?, error: Throwable?)
}

class AnalyticsHttpException(
    val statusCode: Int,
    val retryable: Boolean,
) : IOException("Analytics request failed with HTTP $statusCode")

class AnalyticsCollectionDisabledException : IOException("Analytics collection is disabled")

class ZoAnalyticsClient @JvmOverloads constructor(
    context: Context,
    val configuration: ZoAnalyticsConfiguration = ZoAnalyticsConfiguration(),
    private val executor: ExecutorService = Executors.newSingleThreadExecutor(),
    collectionEnabled: Boolean = true,
) {
    private val appContext = context.applicationContext
    private val queue = AnalyticsQueue(
        appContext,
        configuration.maxPendingSessions,
        configuration.retentionDays,
    )
    private val mainHandler = Handler(Looper.getMainLooper())
    @Volatile
    private var collectionEnabled = collectionEnabled

    @JvmOverloads
    fun submit(
        summary: PlaybackSummary,
        credentials: AnalyticsCredentials,
        callback: AnalyticsCallback? = null,
    ) {
        if (!collectionEnabled) {
            callback?.let { mainHandler.post { it.onComplete(null) } }
            return
        }
        executor.execute {
            val result = runCatching {
                if (!collectionEnabled) return@runCatching
                queue.upsertPlayback(summary, credentials.ownerKey)
                flushBlocking(credentials)
            }
            callback?.let { mainHandler.post { it.onComplete(result.exceptionOrNull()) } }
        }
    }

    fun saveLocally(summary: PlaybackSummary, ownerKey: String) {
        if (!collectionEnabled) return
        executor.execute {
            if (collectionEnabled) queue.upsertPlayback(summary, ownerKey)
        }
    }

    @JvmOverloads
    fun track(
        event: ProductEvent,
        context: AnalyticsContext,
        credentials: AnalyticsCredentials,
        flushImmediately: Boolean = false,
        callback: AnalyticsCallback? = null,
    ) {
        if (!collectionEnabled) {
            callback?.let { mainHandler.post { it.onComplete(null) } }
            return
        }
        executor.execute {
            val result = runCatching {
                if (!collectionEnabled) return@runCatching
                queue.enqueueEvent(event, context, credentials.ownerKey)
                if (flushImmediately) flushEventsBlocking(credentials)
            }
            callback?.let { mainHandler.post { it.onComplete(result.exceptionOrNull()) } }
        }
    }

    @JvmOverloads
    fun submitPlaybackError(
        error: PlaybackErrorEvent,
        sessionId: String,
        platform: AnalyticsPlatform,
        credentials: AnalyticsCredentials,
        callback: AnalyticsCallback? = null,
    ) {
        if (!collectionEnabled) {
            callback?.let { mainHandler.post { it.onComplete(null) } }
            return
        }
        executor.execute {
            val result = runCatching {
                if (!collectionEnabled) return@runCatching
                send(
                    "POST",
                    "api/v4/analytic/playback/$sessionId/errors",
                    error.toJson(),
                    credentials,
                    platform.wireValue,
                )
            }
            callback?.let { mainHandler.post { it.onComplete(result.exceptionOrNull()) } }
        }
    }

    @JvmOverloads
    fun flush(credentials: AnalyticsCredentials, callback: AnalyticsCallback? = null) {
        if (!collectionEnabled) {
            callback?.let { mainHandler.post { it.onComplete(null) } }
            return
        }
        executor.execute {
            val result = runCatching { flushBlocking(credentials) }
            callback?.let { mainHandler.post { it.onComplete(result.exceptionOrNull()) } }
        }
    }

    fun clearPending(ownerKey: String) {
        executor.execute { queue.clear(ownerKey) }
    }

    fun fetchRemoteConfiguration(
        credentials: AnalyticsCredentials,
        platform: AnalyticsPlatform,
        callback: AnalyticsConfigurationCallback,
    ) {
        if (!collectionEnabled) {
            mainHandler.post { callback.onComplete(null, AnalyticsCollectionDisabledException()) }
            return
        }
        executor.execute {
            val result = runCatching {
                if (!collectionEnabled) throw AnalyticsCollectionDisabledException()
                val response = get("api/v4/analytic/config", credentials, platform.wireValue)
                AnalyticsRemoteConfiguration.fromJson(response.getJSONObject("data"))
            }
            mainHandler.post { callback.onComplete(result.getOrNull(), result.exceptionOrNull()) }
        }
    }

    fun pendingCounts(ownerKey: String, callback: (playback: Int, events: Int) -> Unit) {
        executor.execute {
            val result = queue.counts(ownerKey)
            mainHandler.post { callback(result.first, result.second) }
        }
    }

    /** Existing queued records are retained while collection is disabled. */
    fun setCollectionEnabled(enabled: Boolean) {
        collectionEnabled = enabled
    }

    fun isCollectionEnabled(): Boolean = collectionEnabled

    internal fun flushBlocking(credentials: AnalyticsCredentials) {
        if (!collectionEnabled) return
        val pending = queue.duePlayback(credentials.ownerKey, 20)
        if (pending.isNotEmpty()) {
            val ids = pending.map { it.getString("session_id") }.toSet()
            try {
                if (pending.size == 1) sendSinglePlayback(pending.first(), credentials)
                else sendPlaybackBatch(pending, credentials)
                queue.removePlayback(credentials.ownerKey, ids)
            } catch (error: AnalyticsHttpException) {
                if (!error.retryable && error.statusCode != 401) queue.removePlayback(credentials.ownerKey, ids)
                else queue.deferPlayback(credentials.ownerKey, ids)
                throw error
            } catch (error: Throwable) {
                queue.deferPlayback(credentials.ownerKey, ids)
                throw error
            }
        }
        flushEventsBlocking(credentials)
    }

    private fun flushEventsBlocking(credentials: AnalyticsCredentials) {
        val pending = queue.dueEvents(credentials.ownerKey, 50)
        if (pending.isEmpty()) return
        val ids = pending.map { it.getString("event_id") }.toSet()
        try {
            pending.groupBy { it.getJSONObject("context").toString() }.forEach { (_, group) ->
                val events = JSONArray()
                group.forEach { events.put(it.getJSONObject("event")) }
                val context = group.first().getJSONObject("context")
                val body = JSONObject()
                    .put("schema_version", 1)
                    .put("events", events)
                    .put("context", context)
                send("POST", "api/v4/analytic/events/batch", body, credentials, context.optString("platform"))
            }
            queue.removeEvents(credentials.ownerKey, ids)
        } catch (error: AnalyticsHttpException) {
            if (!error.retryable && error.statusCode != 401) queue.removeEvents(credentials.ownerKey, ids)
            else queue.deferEvents(credentials.ownerKey, ids)
            throw error
        } catch (error: Throwable) {
            queue.deferEvents(credentials.ownerKey, ids)
            throw error
        }
    }

    private fun sendSinglePlayback(item: JSONObject, credentials: AnalyticsCredentials) {
        val sessionId = item.getString("session_id")
        val payload = item.getJSONObject("payload")
        val platform = payload.getJSONObject("context").optString("platform", "android")
        send("PUT", "api/v4/analytic/playback/$sessionId", payload, credentials, platform)
    }

    private fun sendPlaybackBatch(items: List<JSONObject>, credentials: AnalyticsCredentials) {
        val sessions = JSONArray()
        items.forEach { item ->
            sessions.put(JSONObject()
                .put("session_id", item.getString("session_id"))
                .put("summary", item.getJSONObject("payload")))
        }
        val body = JSONObject().put("schema_version", 1).put("sessions", sessions)
        val platform = items.first().getJSONObject("payload")
            .getJSONObject("context").optString("platform", "android")
        send("POST", "api/v4/analytic/playback/batch", body, credentials, platform)
    }

    private fun send(
        method: String,
        path: String,
        body: JSONObject,
        credentials: AnalyticsCredentials,
        platform: String,
    ) {
        val bytes = body.toString().toByteArray(Charsets.UTF_8)
        val payloadLimit = if (path.endsWith("/batch")) 262_144 else 65_536
        if (bytes.size > payloadLimit) throw IOException("Analytics payload exceeds the route limit")
        val connection = URL(configuration.endpoint(path)).openConnection() as HttpURLConnection
        try {
            connection.requestMethod = method
            connection.connectTimeout = configuration.connectTimeoutMs
            connection.readTimeout = configuration.readTimeoutMs
            connection.doOutput = true
            connection.setRequestProperty("Content-Type", "application/json")
            connection.setRequestProperty("Accept", "application/json")
            connection.setRequestProperty("Authorization", "Bearer ${credentials.accessToken}")
            connection.setRequestProperty("Device-Token", credentials.deviceToken)
            connection.setRequestProperty("X-Analytics-SDK-Version", ZoAnalyticsConfiguration.SDK_VERSION)
            connection.setRequestProperty("X-Platform", platform)
            connection.outputStream.use { it.write(bytes) }
            val status = connection.responseCode
            if (status !in 200..299) {
                val retryable = status == 404 || status == 408 || status == 429 || status >= 500
                throw AnalyticsHttpException(status, retryable)
            }
        } finally {
            connection.disconnect()
        }
    }

    private fun get(
        path: String,
        credentials: AnalyticsCredentials,
        platform: String,
    ): JSONObject {
        val connection = URL(configuration.endpoint(path)).openConnection() as HttpURLConnection
        try {
            connection.requestMethod = "GET"
            connection.connectTimeout = configuration.connectTimeoutMs
            connection.readTimeout = configuration.readTimeoutMs
            connection.setRequestProperty("Accept", "application/json")
            connection.setRequestProperty("Authorization", "Bearer ${credentials.accessToken}")
            connection.setRequestProperty("Device-Token", credentials.deviceToken)
            connection.setRequestProperty("X-Analytics-SDK-Version", ZoAnalyticsConfiguration.SDK_VERSION)
            connection.setRequestProperty("X-Platform", platform)
            val status = connection.responseCode
            if (status !in 200..299) {
                val retryable = status == 404 || status == 408 || status == 429 || status >= 500
                throw AnalyticsHttpException(status, retryable)
            }
            return connection.inputStream.bufferedReader().use { JSONObject(it.readText()) }
        } finally {
            connection.disconnect()
        }
    }
}
