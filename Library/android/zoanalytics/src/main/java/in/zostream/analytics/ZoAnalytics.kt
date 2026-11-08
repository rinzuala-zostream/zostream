package `in`.zostream.analytics

import android.app.Activity
import android.app.Application
import android.os.Bundle
import java.util.UUID
import kotlin.math.min
import kotlin.random.Random

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
    private val presenceHandler = android.os.Handler(android.os.Looper.getMainLooper())
    private val presenceRunnable = object : Runnable {
        override fun run() {
            refreshCollectionState()
            if (!isCollectionEnabled || startedActivities <= 0) return
            val credentials = credentialsProvider()
            if (credentials != null) {
                client.updatePresence(AnalyticsPresenceState.FOREGROUND, contextProvider(), credentials)
            }
            val jitter = min(10_000L, configuration.presenceHeartbeatIntervalMs / 6)
            val delay = configuration.presenceHeartbeatIntervalMs + Random.nextLong(-jitter, jitter + 1)
            presenceHandler.postDelayed(this, delay.coerceAtLeast(30_000L))
        }
    }

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
        if (startedActivities > 0) startPresenceHeartbeat()
    }

    /** Call whenever the host's remote collection flag changes. */
    fun collectionStateDidChange() {
        refreshCollectionState()
        if (isCollectionEnabled) {
            recordOpenIfPossible()
            flush()
            if (startedActivities > 0) startPresenceHeartbeat()
        } else {
            stopPresenceHeartbeat(sendOffline = false)
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
        stopPresenceHeartbeat(sendOffline = true)
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
            startPresenceHeartbeat()
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
            stopPresenceHeartbeat(sendOffline = true)
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

    private fun startPresenceHeartbeat() {
        if (!isCollectionEnabled || startedActivities <= 0) return
        presenceHandler.removeCallbacks(presenceRunnable)
        presenceHandler.post(presenceRunnable)
    }

    private fun stopPresenceHeartbeat(sendOffline: Boolean) {
        presenceHandler.removeCallbacks(presenceRunnable)
        if (!sendOffline || !isCollectionEnabled) return
        val credentials = credentialsProvider() ?: return
        client.updatePresence(AnalyticsPresenceState.BACKGROUND, contextProvider(), credentials)
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
