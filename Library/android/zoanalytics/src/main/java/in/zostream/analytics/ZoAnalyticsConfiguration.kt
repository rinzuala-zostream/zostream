package `in`.zostream.analytics

data class ZoAnalyticsConfiguration @JvmOverloads constructor(
    val baseUrl: String = "https://zostream.in/",
    val maxPendingSessions: Int = 500,
    val retentionDays: Int = 7,
    val connectTimeoutMs: Int = 10_000,
    val readTimeoutMs: Int = 15_000,
    val automaticLifecycleTracking: Boolean = true,
    val automaticActivityScreenTracking: Boolean = true,
    val presenceHeartbeatIntervalMs: Long = 60_000,
) {
    init {
        require(baseUrl.startsWith("https://")) { "Analytics base URL must use HTTPS" }
        require(maxPendingSessions > 0)
        require(retentionDays > 0)
        require(presenceHeartbeatIntervalMs >= 30_000)
    }

    internal fun endpoint(path: String): String = baseUrl.trimEnd('/') + "/" + path.trimStart('/')

    companion object {
        const val SDK_VERSION = "1.4.0"
    }
}
