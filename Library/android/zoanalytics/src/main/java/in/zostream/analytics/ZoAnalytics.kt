package `in`.zostream.analytics

import android.app.Activity
import android.app.Application
import android.os.Bundle
import java.util.UUID

/** Application-scoped facade. Call [start] once from Application.onCreate. */
class ZoAnalytics private constructor(
    private val application: Application,
    val client: ZoAnalyticsClient,
    private val credentialsProvider: () -> AnalyticsCredentials?,
    private val contextProvider: () -> AnalyticsContext,
    private val collectionEnabledProvider: () -> Boolean,
    private val configuration: ZoAnalyticsConfiguration,
) : Application.ActivityLifecycleCallbacks {
    var appSessionId: String = UUID.randomUUID().toString().lowercase()
        private set

    private val sessionStartedAt = System.currentTimeMillis()
    private var startedActivities = 0
    private var changingConfigurations = 0
    private var enteredBackground = false
    private var recordedOpen = false
    private var lastScreenName: String? = null

    init {
        if (configuration.automaticLifecycleTracking) {
            application.registerActivityLifecycleCallbacks(this)
        }
        recordOpenIfPossible()
    }

    val isCollectionEnabled: Boolean
        get() = collectionEnabledProvider()

    /** Call after login or token refresh so a launch that began anonymously is recorded. */
    fun credentialsDidChange() {
        refreshCollectionState()
        if (!isCollectionEnabled) return
        recordOpenIfPossible()
        flush()
    }

    /** Call whenever the host's remote collection flag changes. */
    fun collectionStateDidChange() {
        refreshCollectionState()
        if (isCollectionEnabled) {
            recordOpenIfPossible()
            flush()
        }
    }

    @JvmOverloads
    fun track(
        name: String,
        properties: Map<String, Any?> = emptyMap(),
        flushImmediately: Boolean = false,
    ) {
        if (name !in ProductEvent.supportedNames) return
        refreshCollectionState()
        if (!isCollectionEnabled) return
        val credentials = credentialsProvider() ?: return
        client.track(
            ProductEvent(name, appSessionId, properties),
            contextProvider(),
            credentials,
            flushImmediately,
        )
    }

    fun screenViewed(name: String) {
        track("screen_viewed", mapOf("screen_name" to name.take(100)))
    }

    fun flush() {
        refreshCollectionState()
        if (!isCollectionEnabled) return
        credentialsProvider()?.let { client.flush(it) }
    }

    @JvmOverloads
    fun stop(recordSessionEnd: Boolean = true) {
        if (recordSessionEnd) {
            track(
                "app_session_ended",
                mapOf("session_duration_ms" to sessionDurationMs()),
            )
            flush()
        }
        application.unregisterActivityLifecycleCallbacks(this)
        synchronized(Companion) {
            if (instance === this) instance = null
        }
    }

    override fun onActivityStarted(activity: Activity) {
        refreshCollectionState()
        if (changingConfigurations > 0) changingConfigurations--
        val enteredForeground = startedActivities++ == 0
        if (!isCollectionEnabled) return
        if (enteredForeground) {
            recordOpenIfPossible()
            if (enteredBackground) {
                enteredBackground = false
                track("app_foregrounded")
                flush()
            }
        }
    }

    override fun onActivityResumed(activity: Activity) {
        if (!isCollectionEnabled) return
        if (configuration.automaticActivityScreenTracking) {
            val name = activity.javaClass.simpleName.removeSuffix("Activity").ifBlank { "unknown" }
            if (lastScreenName != name) {
                lastScreenName = name
                screenViewed(name)
            }
        }
    }

    override fun onActivityStopped(activity: Activity) {
        if (activity.isChangingConfigurations) changingConfigurations++
        startedActivities = (startedActivities - 1).coerceAtLeast(0)
        refreshCollectionState()
        if (!isCollectionEnabled) return
        if (startedActivities == 0 && changingConfigurations == 0) {
            enteredBackground = true
            lastScreenName = null
            track(
                "app_backgrounded",
                mapOf("session_duration_ms" to sessionDurationMs()),
            )
            flush()
        }
    }

    override fun onActivityCreated(activity: Activity, state: Bundle?) = Unit
    override fun onActivityPaused(activity: Activity) = Unit
    override fun onActivitySaveInstanceState(activity: Activity, state: Bundle) = Unit
    override fun onActivityDestroyed(activity: Activity) = Unit

    private fun recordOpenIfPossible() {
        if (isCollectionEnabled && !recordedOpen && credentialsProvider() != null) {
            recordedOpen = true
            track("app_opened")
            flush()
        }
    }

    private fun sessionDurationMs(): Long =
        (System.currentTimeMillis() - sessionStartedAt).coerceAtLeast(0)

    private fun refreshCollectionState() {
        client.setCollectionEnabled(isCollectionEnabled)
    }

    companion object {
        @Volatile
        private var instance: ZoAnalytics? = null

        @JvmStatic
        @Synchronized
        fun start(
            application: Application,
            configuration: ZoAnalyticsConfiguration = ZoAnalyticsConfiguration(),
            collectionEnabled: () -> Boolean = { false },
            credentials: () -> AnalyticsCredentials?,
            context: () -> AnalyticsContext,
        ): ZoAnalytics {
            instance?.stop(recordSessionEnd = false)
            return ZoAnalytics(
                application,
                ZoAnalyticsClient(
                    application,
                    configuration,
                    collectionEnabled = collectionEnabled(),
                ),
                credentials,
                context,
                collectionEnabled,
                configuration,
            ).also { instance = it }
        }

        @JvmStatic
        fun current(): ZoAnalytics? = instance
    }
}
