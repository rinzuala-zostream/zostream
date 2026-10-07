package `in`.zostream.analytics

import android.app.UiModeManager
import android.content.Context
import android.content.pm.PackageManager
import android.content.res.Configuration
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.Build
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.UUID

enum class AnalyticsPlatform(val wireValue: String) {
    ANDROID("android"), TV("tv")
}

enum class PlaybackContentType(val wireValue: String) {
    MOVIE("movie"), EPISODE("episode"), LIVE("live")
}

enum class PlaybackUploadState(val wireValue: String) {
    CHECKPOINT("checkpoint"), FINAL("final")
}

enum class PlaybackEndReason(val wireValue: String) {
    COMPLETED("completed"),
    USER_CLOSED("user_closed"),
    BACK_PRESSED("back_pressed"),
    CONTENT_CHANGED("content_changed"),
    NEXT_EPISODE("next_episode"),
    APP_BACKGROUNDED("app_backgrounded"),
    APP_TERMINATED("app_terminated"),
    PLAYBACK_ERROR("playback_error"),
    NETWORK_LOST("network_lost"),
    SUBSCRIPTION_EXPIRED("subscription_expired"),
    PLAYER_DESTROYED("player_destroyed"),
    UNKNOWN("unknown")
}

enum class NetworkType(val wireValue: String) {
    WIFI("wifi"), CELLULAR("cellular"), ETHERNET("ethernet"), OFFLINE("offline"), UNKNOWN("unknown")
}

enum class AnalyticsPresenceState(val wireValue: String) {
    FOREGROUND("foreground"), BACKGROUND("background")
}

data class AnalyticsCredentials(
    val accessToken: String,
    val deviceToken: String,
    val ownerKey: String,
)

data class AnalyticsRemoteConfiguration(
    val enabled: Boolean,
    val schemaVersion: Int,
    val minimumSdkVersion: String,
    val checkpointUploadEnabled: Boolean,
    val localSnapshotIntervalSeconds: Int,
    val maxPendingSessions: Int,
    val pendingRetentionDays: Int,
    val maxBatchSize: Int,
    val maxPayloadBytes: Int,
    val sampleRate: Double,
    val presenceHeartbeatEnabled: Boolean,
    val presenceHeartbeatIntervalSeconds: Int,
    val presenceTtlSeconds: Int,
) {
    companion object {
        fun fromJson(json: JSONObject) = AnalyticsRemoteConfiguration(
            enabled = json.optBoolean("enabled", true),
            schemaVersion = json.optInt("schema_version", 1),
            minimumSdkVersion = json.optString("minimum_sdk_version", "1.0.0"),
            checkpointUploadEnabled = json.optBoolean("checkpoint_upload_enabled", false),
            localSnapshotIntervalSeconds = json.optInt("local_snapshot_interval_seconds", 30),
            maxPendingSessions = json.optInt("max_pending_sessions", 500),
            pendingRetentionDays = json.optInt("pending_retention_days", 7),
            maxBatchSize = json.optInt("max_batch_size", 20),
            maxPayloadBytes = json.optInt("max_payload_bytes", 65_536),
            sampleRate = json.optDouble("sample_rate", 1.0),
            presenceHeartbeatEnabled = json.optBoolean("presence_heartbeat_enabled", false),
            presenceHeartbeatIntervalSeconds = json.optInt("presence_heartbeat_interval_seconds", 60),
            presenceTtlSeconds = json.optInt("presence_ttl_seconds", 150),
        )
    }
}

data class PlaybackContent @JvmOverloads constructor(
    val id: String,
    val type: PlaybackContentType,
    val seriesId: String? = null,
    val seasonId: String? = null,
    val episodeId: String? = null,
    val isDownloaded: Boolean = false,
    val autoplay: Boolean = false,
) {
    fun toJson() = JSONObject()
        .put("id", id)
        .put("type", type.wireValue)
        .putNullable("series_id", seriesId)
        .putNullable("season_id", seasonId)
        .putNullable("episode_id", episodeId)
        .put("is_downloaded", isDownloaded)
        .put("autoplay", autoplay)
}

data class AnalyticsContext @JvmOverloads constructor(
    val appVersion: String,
    val buildNumber: String,
    val platform: AnalyticsPlatform = AnalyticsPlatform.ANDROID,
    val networkType: NetworkType = NetworkType.UNKNOWN,
    val osVersion: String = Build.VERSION.RELEASE ?: "unknown",
    val deviceModel: String = "${Build.MANUFACTURER} ${Build.MODEL}".trim(),
    val deviceCategory: String = "phone",
    val locale: String = Locale.getDefault().toLanguageTag(),
    val timezone: String = TimeZone.getDefault().id,
) {
    fun toJson() = JSONObject()
        .put("platform", platform.wireValue)
        .put("network_type", networkType.wireValue)
        .put("app_version", appVersion)
        .put("build_number", buildNumber)
        .put("os_version", osVersion)
        .put("device_model", deviceModel)
        .put("device_category", deviceCategory)
        .put("locale", locale)
        .put("timezone", timezone)

    companion object {
        /** Builds a context that reports Android TV separately from mobile Android. */
        @JvmStatic
        @JvmOverloads
        fun fromAndroidContext(
            context: Context,
            appVersion: String,
            buildNumber: String,
            networkType: NetworkType? = null,
        ): AnalyticsContext {
            val appContext = context.applicationContext
            val modeManager = appContext.getSystemService(Context.UI_MODE_SERVICE) as? UiModeManager
            val packageManager = appContext.packageManager
            val isTelevision = modeManager?.currentModeType == Configuration.UI_MODE_TYPE_TELEVISION ||
                packageManager.hasSystemFeature(PackageManager.FEATURE_LEANBACK)
            val (platform, category) = deviceIdentity(
                isTelevision,
                appContext.resources.configuration.smallestScreenWidthDp,
            )

            return AnalyticsContext(
                appVersion = appVersion,
                buildNumber = buildNumber,
                platform = platform,
                networkType = networkType ?: detectNetworkType(appContext),
                deviceCategory = category,
            )
        }

        /** Detects the active transport. Requires ACCESS_NETWORK_STATE in the host app. */
        @Suppress("DEPRECATION")
        @JvmStatic
        fun detectNetworkType(context: Context): NetworkType {
            val connectivity = context.applicationContext
                .getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
                ?: return NetworkType.UNKNOWN

            return try {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                    val network = connectivity.activeNetwork ?: return NetworkType.OFFLINE
                    val capabilities = connectivity.getNetworkCapabilities(network)
                        ?: return NetworkType.UNKNOWN
                    when {
                        capabilities.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) -> NetworkType.WIFI
                        capabilities.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) -> NetworkType.CELLULAR
                        capabilities.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET) -> NetworkType.ETHERNET
                        else -> NetworkType.UNKNOWN
                    }
                } else {
                    val info = connectivity.activeNetworkInfo ?: return NetworkType.OFFLINE
                    if (!info.isConnected) return NetworkType.OFFLINE
                    when (info.type) {
                        ConnectivityManager.TYPE_WIFI -> NetworkType.WIFI
                        ConnectivityManager.TYPE_MOBILE -> NetworkType.CELLULAR
                        ConnectivityManager.TYPE_ETHERNET -> NetworkType.ETHERNET
                        else -> NetworkType.UNKNOWN
                    }
                }
            } catch (_: SecurityException) {
                NetworkType.UNKNOWN
            }
        }

        internal fun deviceIdentity(
            isTelevision: Boolean,
            smallestScreenWidthDp: Int,
        ): Pair<AnalyticsPlatform, String> = when {
            isTelevision -> AnalyticsPlatform.TV to "tv"
            smallestScreenWidthDp >= 600 -> AnalyticsPlatform.ANDROID to "tablet"
            else -> AnalyticsPlatform.ANDROID to "phone"
        }

        fun fromJson(json: JSONObject) = AnalyticsContext(
            platform = AnalyticsPlatform.entries.firstOrNull { it.wireValue == json.optString("platform") }
                ?: AnalyticsPlatform.ANDROID,
            networkType = NetworkType.entries.firstOrNull { it.wireValue == json.optString("network_type") }
                ?: NetworkType.UNKNOWN,
            appVersion = json.optString("app_version"),
            buildNumber = json.optString("build_number"),
            osVersion = json.optString("os_version"),
            deviceModel = json.optString("device_model"),
            deviceCategory = json.optString("device_category"),
            locale = json.optString("locale"),
            timezone = json.optString("timezone"),
        )
    }
}

data class PlaybackSummary(
    val sessionId: String,
    val revision: Int,
    val state: PlaybackUploadState,
    val payload: JSONObject,
)

data class ProductEvent @JvmOverloads constructor(
    val name: String,
    val appSessionId: String,
    val properties: Map<String, Any?> = emptyMap(),
    val eventId: String = UUID.randomUUID().toString().lowercase(),
    val occurredAt: String = isoTimestamp(System.currentTimeMillis()),
) {
    init {
        require(name in supportedNames) { "Unsupported analytics event: $name" }
    }

    fun toJson(): JSONObject {
        val propertyJson = JSONObject()
        properties.forEach { (key, value) -> propertyJson.putSanitized(key, value) }
        return JSONObject()
            .put("event_id", eventId)
            .put("name", name)
            .put("occurred_at", occurredAt)
            .put("app_session_id", appSessionId)
            .put("properties", propertyJson)
    }

    companion object {
        val supportedNames = setOf(
            "app_opened",
            "app_foregrounded",
            "app_backgrounded",
            "app_session_ended",
            "screen_viewed",
            "content_impression",
            "content_opened",
            "search_performed",
            "search_result_selected",
            "search_empty",
            "wishlist_added",
            "wishlist_removed",
            "download_started",
            "download_completed",
            "download_failed",
            "notification_opened",
            "paywall_viewed",
            "plan_selected",
            "purchase_started",
            "purchase_completed",
            "purchase_failed",
            "purchase_cancelled",
            "restore_purchase_completed",
        )
    }
}

data class PlaybackErrorEvent @JvmOverloads constructor(
    val positionMs: Long,
    val category: String,
    val stage: String,
    val code: String,
    val isFatal: Boolean,
    val isRetryable: Boolean,
    val httpStatus: Int? = null,
    val retryCount: Int = 0,
    val networkType: NetworkType = NetworkType.UNKNOWN,
    val sanitizedMessage: String? = null,
    val eventId: String = UUID.randomUUID().toString().lowercase(),
    val occurredAt: String = isoTimestamp(System.currentTimeMillis()),
) {
    fun toJson() = JSONObject()
        .put("schema_version", 1)
        .put("event_id", eventId)
        .put("occurred_at", occurredAt)
        .put("position_ms", maxOf(0, positionMs))
        .put("category", category.take(64))
        .put("stage", stage.take(64))
        .put("code", code.take(128))
        .putNullable("http_status", httpStatus)
        .put("is_fatal", isFatal)
        .put("is_retryable", isRetryable)
        .put("retry_count", maxOf(0, retryCount))
        .put("network_type", networkType.wireValue)
        .putNullable("sanitized_message", sanitizedMessage?.take(500))
}

internal fun JSONObject.putNullable(key: String, value: Any?): JSONObject =
    put(key, value ?: JSONObject.NULL)

internal fun JSONObject.putSanitized(key: String, value: Any?): JSONObject {
    require(key.length <= 64) { "Analytics property name is too long" }
    val sanitized = when (value) {
        null -> JSONObject.NULL
        is String -> value.take(255)
        is Boolean, is Int, is Long, is Float, is Double -> value
        is List<*> -> JSONArray(value.take(20))
        else -> value.toString().take(255)
    }
    return put(key, sanitized)
}

internal fun JSONObject.copyObject(): JSONObject = JSONObject(toString())

internal fun isoTimestamp(epochMs: Long): String =
    SimpleDateFormat("yyyy-MM-dd'T'HH:mm:ss.SSS'Z'", Locale.US).apply {
        timeZone = TimeZone.getTimeZone("UTC")
    }.format(Date(epochMs))
