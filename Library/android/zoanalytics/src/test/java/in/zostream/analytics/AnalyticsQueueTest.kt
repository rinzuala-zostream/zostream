package `in`.zostream.analytics

import org.json.JSONObject
import org.junit.Assert.assertEquals
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.RuntimeEnvironment

@RunWith(RobolectricTestRunner::class)
class AnalyticsQueueTest {
    @Test
    fun errorQueueSurvivesRestartAndKeepsAccountsSeparate() {
        val context = RuntimeEnvironment.getApplication()
        context.filesDir.resolve("zoanalytics").deleteRecursively()
        val queue = AnalyticsQueue(context, maxPendingSessions = 5, retentionDays = 7)
        val event = PlaybackErrorEvent(100, "player", "media3", "ERROR_CODE_IO", true, false)

        queue.enqueueError(event, "session-a", AnalyticsPlatform.ANDROID, "owner-a", now = 1_000)
        queue.enqueueError(event, "session-a", AnalyticsPlatform.ANDROID, "owner-a", now = 1_000)

        val restored = AnalyticsQueue(context, maxPendingSessions = 5, retentionDays = 7)
        assertEquals(1, restored.dueErrors("owner-a", 10, now = 1_000).size)
        assertEquals(0, restored.dueErrors("owner-b", 10, now = 1_000).size)
        restored.removeErrors("owner-a", setOf(event.eventId))
        assertEquals(0, restored.dueErrors("owner-a", 10, now = 1_000).size)
    }

    @Test
    fun keepsNewestRevisionForOneSession() {
        val context = RuntimeEnvironment.getApplication()
        context.filesDir.resolve("zoanalytics").deleteRecursively()
        val queue = AnalyticsQueue(context, maxPendingSessions = 5, retentionDays = 7)
        val base = JSONObject()
            .put("schema_version", 1)
            .put("revision", 1)
            .put("state", "checkpoint")
            .put("context", JSONObject().put("platform", "android"))
        val newer = JSONObject(base.toString()).put("revision", 2)

        queue.upsertPlayback(
            PlaybackSummary("session", 2, PlaybackUploadState.CHECKPOINT, newer),
            "owner",
            now = 1_000,
        )
        queue.upsertPlayback(
            PlaybackSummary("session", 1, PlaybackUploadState.CHECKPOINT, base),
            "owner",
            now = 1_000,
        )

        val pending = queue.duePlayback("owner", 10, now = 1_000)
        assertEquals(1, pending.size)
        assertEquals(2, pending.first().getInt("revision"))
    }
}
