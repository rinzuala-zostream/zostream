package `in`.zostream.analytics

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner

@RunWith(RobolectricTestRunner::class)
class PlaybackAnalyticsSessionTest {
    private class Clock {
        var elapsed = 1_000L
        var wall = 1_700_000_000_000L
        fun advance(milliseconds: Long) {
            elapsed += milliseconds
            wall += milliseconds
        }
    }

    private fun context() = AnalyticsContext(
        appVersion = "1.0",
        buildNumber = "1",
        osVersion = "test",
        deviceModel = "test",
        locale = "en-IN",
        timezone = "Asia/Kolkata",
    )

    @Test
    fun countsOnlyPlayingTimeAndMergesRanges() {
        val clock = Clock()
        val session = PlaybackAnalyticsSession(
            content = PlaybackContent("movie-1", PlaybackContentType.MOVIE),
            context = context(),
            sessionId = "session-1",
            wallClock = { clock.wall },
            elapsedClock = { clock.elapsed },
        )

        session.recordPlay(0)
        clock.advance(10_000)
        session.tick(10_000, 100_000)
        session.recordPause(10_000)
        clock.advance(5_000)
        session.tick(10_000, 100_000)
        session.recordPlay(10_000)
        clock.advance(10_000)
        session.tick(20_000, 100_000)

        val payload = session.finish(PlaybackEndReason.USER_CLOSED).payload
        assertEquals(20_000, payload.getJSONObject("timing").getLong("watched_ms"))
        assertEquals(20_000, payload.getJSONObject("timing").getLong("unique_watched_ms"))
        assertEquals(1, payload.getJSONObject("interaction").getInt("pause_count"))
        assertFalse(payload.getJSONObject("result").getBoolean("completed"))
    }

    @Test
    fun seekingNearEndDoesNotCompletePlayback() {
        val clock = Clock()
        val session = PlaybackAnalyticsSession(
            content = PlaybackContent("movie-1", PlaybackContentType.MOVIE),
            context = context(),
            sessionId = "session-2",
            wallClock = { clock.wall },
            elapsedClock = { clock.elapsed },
        )
        session.recordPlay(0)
        clock.advance(5_000)
        session.tick(5_000, 100_000)
        session.recordSeek(5_000, 95_000)
        clock.advance(1_000)
        session.tick(96_000, 100_000)

        val payload = session.finish(PlaybackEndReason.USER_CLOSED).payload
        assertEquals(6_000, payload.getJSONObject("timing").getLong("unique_watched_ms"))
        assertFalse(payload.getJSONObject("result").getBoolean("completed"))
    }

    @Test
    fun naturalEndCompletesPlayback() {
        val clock = Clock()
        val session = PlaybackAnalyticsSession(
            content = PlaybackContent("episode-1", PlaybackContentType.EPISODE),
            context = context(),
            wallClock = { clock.wall },
            elapsedClock = { clock.elapsed },
        )
        session.recordPlay(0)
        clock.advance(5_000)
        session.recordNaturalEnd(5_000, 60_000)

        val payload = session.finish(PlaybackEndReason.PLAYER_DESTROYED).payload
        assertTrue(payload.getJSONObject("result").getBoolean("completed"))
        assertEquals("completed", payload.getString("end_reason"))
    }
}
