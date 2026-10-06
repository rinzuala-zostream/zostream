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
