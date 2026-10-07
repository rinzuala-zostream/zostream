package `in`.zostream.analytics

import android.os.SystemClock
import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID
import kotlin.math.max
import kotlin.math.min
import kotlin.math.roundToLong

class PlaybackAnalyticsSession @JvmOverloads constructor(
    val content: PlaybackContent,
    val context: AnalyticsContext,
    val sessionId: String = UUID.randomUUID().toString().lowercase(),
    private val wallClock: () -> Long = { System.currentTimeMillis() },
    private val elapsedClock: () -> Long = { SystemClock.elapsedRealtime() },
) {
    private data class WatchedRange(var lower: Long, var upper: Long)

    private val startedAtMs = wallClock()
    private val startedElapsedMs = elapsedClock()
    private var lastAccountingMs = startedElapsedMs
    private var firstFrameElapsedMs: Long? = null
    private var bufferStartedMs: Long? = null
    private var currentBufferCounts = false
    private var lastSamplePositionMs: Long? = null
    private var lastSampleElapsedMs: Long? = null

    private var revision = 0
    private var playing = false
    private var buffering = false
    private var foreground = true
    private var naturalEnd = false
    private var finished = false

    private var durationMs = 0L
    private var positionMs = 0L
    private var maxPositionMs = 0L
    private var watchedMs = 0L
    private var foregroundWatchMs = 0L
    private var backgroundPlayMs = 0L
    private val watchedRanges = mutableListOf<WatchedRange>()

    private var playCount = 0
    private var pauseCount = 0
    private var resumeCount = 0
    private var seekCount = 0
    private var seekForwardMs = 0L
    private var seekBackwardMs = 0L
    private var fullscreenCount = 0
    private var pipCount = 0
    private var castCount = 0

    private var bufferCount = 0
    private var bufferTotalMs = 0L
    private var longestBufferMs = 0L
    private var errorCount = 0

    private var initialQuality: String? = null
    private var finalQuality: String? = null
    private var qualityChangeCount = 0
    private var averageBitrateKbps: Int? = null
    private var bytesTransferred: Long? = null
    private var droppedFrames: Int? = null
    private var renderedFrames: Int? = null
    private var videoCodec: String? = null
    private var audioCodec: String? = null
    private var streamFormat: String? = null

    private var audioLanguage: String? = null
    private var subtitleEnabled = false
    private var subtitleLanguage: String? = null
    private var playbackSpeed = 1.0

    @Synchronized
    fun recordFirstFrame(positionMs: Long = 0) {
        if (finished) return
        val now = elapsedClock()
        if (firstFrameElapsedMs == null) firstFrameElapsedMs = now
        recordPosition(max(0, positionMs), now)
    }

    @Synchronized
    fun recordPlay(positionMs: Long) {
        if (finished) return
        val now = elapsedClock()
        accountElapsed(now)
        if (!playing) {
            if (playCount == 0) playCount = 1 else resumeCount++
        }
        playing = true
        recordPosition(max(0, positionMs), now)
    }

    @Synchronized
    fun recordPause(positionMs: Long) {
        if (finished) return
        val now = elapsedClock()
        accountElapsed(now)
        if (playing) pauseCount++
        playing = false
        recordPosition(max(0, positionMs), now)
    }

    @Synchronized
    fun recordSeek(fromMs: Long, toMs: Long) {
        if (finished) return
        val now = elapsedClock()
        accountElapsed(now)
        val delta = toMs - fromMs
        seekCount++
        if (delta >= 0) seekForwardMs += delta else seekBackwardMs += -delta
        positionMs = max(0, toMs)
        maxPositionMs = max(maxPositionMs, positionMs)
        lastSamplePositionMs = positionMs
        lastSampleElapsedMs = now
    }

    @Synchronized
    fun recordBufferingStarted(positionMs: Long) {
        if (finished || buffering) return
        val now = elapsedClock()
        accountElapsed(now)
        buffering = true
        bufferStartedMs = now
        currentBufferCounts = firstFrameElapsedMs != null
        if (currentBufferCounts) bufferCount++
        recordPosition(max(0, positionMs), now)
    }

    @Synchronized
    fun recordBufferingEnded(positionMs: Long) {
        if (finished || !buffering) return
        val now = elapsedClock()
        finishBuffer(now)
        buffering = false
        lastAccountingMs = now
        recordPosition(max(0, positionMs), now)
    }

    @Synchronized
    fun tick(positionMs: Long, durationMs: Long) {
        if (finished) return
        val now = elapsedClock()
        accountElapsed(now)
        this.durationMs = max(this.durationMs, max(0, durationMs))
        recordPosition(max(0, positionMs), now)
    }

    @Synchronized
    fun recordNaturalEnd(positionMs: Long, durationMs: Long) {
        if (finished) return
        val now = elapsedClock()
        accountElapsed(now)
        naturalEnd = true
        playing = false
        this.durationMs = max(this.durationMs, max(0, durationMs))
        recordPosition(max(0, positionMs), now)
    }

    @Synchronized fun recordError() { if (!finished) errorCount++ }
    @Synchronized fun recordFullscreenEntered() { fullscreenCount++ }
    @Synchronized fun recordPictureInPictureStarted() { pipCount++ }
    @Synchronized fun recordCastStarted() { castCount++ }

    @Synchronized
    fun setForeground(value: Boolean) {
        accountElapsed(elapsedClock())
        foreground = value
    }

    @JvmOverloads
    @Synchronized
    fun setTracks(
        audioLanguage: String? = null,
        subtitleEnabled: Boolean? = null,
        subtitleLanguage: String? = null,
        playbackSpeed: Double? = null,
    ) {
        if (audioLanguage != null) this.audioLanguage = audioLanguage
        if (subtitleEnabled != null) this.subtitleEnabled = subtitleEnabled
        if (subtitleLanguage != null) this.subtitleLanguage = subtitleLanguage
        if (playbackSpeed != null) this.playbackSpeed = playbackSpeed.coerceIn(0.25, 4.0)
    }

    @JvmOverloads
    @Synchronized
    fun recordQuality(
        label: String? = null,
        averageBitrateKbps: Int? = null,
        droppedFrames: Int? = null,
        renderedFrames: Int? = null,
        videoCodec: String? = null,
        audioCodec: String? = null,
        streamFormat: String? = null,
        bytesTransferred: Long? = null,
    ) {
        if (!label.isNullOrBlank()) {
            if (initialQuality == null) initialQuality = label
            if (finalQuality != null && finalQuality != label) qualityChangeCount++
            finalQuality = label
        }
        if (averageBitrateKbps != null) this.averageBitrateKbps = max(0, averageBitrateKbps)
        if (bytesTransferred != null) {
            this.bytesTransferred = max(this.bytesTransferred ?: 0, bytesTransferred)
        }
        if (droppedFrames != null) this.droppedFrames = max(0, droppedFrames)
        if (renderedFrames != null) this.renderedFrames = max(0, renderedFrames)
        if (videoCodec != null) this.videoCodec = videoCodec
        if (audioCodec != null) this.audioCodec = audioCodec
        if (streamFormat != null) this.streamFormat = streamFormat
    }

    @Synchronized
    fun checkpoint(): PlaybackSummary = makeSummary(PlaybackUploadState.CHECKPOINT, null, false)

    @Synchronized
    fun finish(reason: PlaybackEndReason): PlaybackSummary =
        makeSummary(PlaybackUploadState.FINAL, reason, true)

    private fun makeSummary(
        state: PlaybackUploadState,
        requestedReason: PlaybackEndReason?,
        markFinished: Boolean,
    ): PlaybackSummary {
        val now = elapsedClock()
        accountElapsed(now)
        if (buffering) finishBuffer(now)
        if (markFinished) {
            playing = false
            buffering = false
            finished = true
        }
        revision++

        val uniqueWatchedMs = mergedRangeDuration()
        val replayedMs = max(0, watchedMs - uniqueWatchedMs)
        val completionPercent = if (durationMs > 0) {
            min(100.0, uniqueWatchedMs.toDouble() / durationMs.toDouble() * 100.0)
        } else 0.0
        val completed = naturalEnd || completionPercent >= 90.0
        val milestones = JSONArray()
        listOf(25, 50, 75, 90).filter { completionPercent >= it }.forEach(milestones::put)

        val payload = JSONObject()
            .put("schema_version", 1)
            .put("revision", revision)
            .put("state", state.wireValue)
            .put("started_at", isoTimestamp(startedAtMs))
            .putNullable("ended_at", if (state == PlaybackUploadState.FINAL) isoTimestamp(wallClock()) else null)
            .putNullable(
                "end_reason",
                if (state == PlaybackUploadState.FINAL) {
                    if (completed) PlaybackEndReason.COMPLETED.wireValue
                    else (requestedReason ?: PlaybackEndReason.UNKNOWN).wireValue
                } else null,
            )
            .put("content", content.toJson())
            .put("timing", JSONObject()
                .put("duration_ms", durationMs)
                .put("watch_position_ms", positionMs)
                .put("max_position_ms", maxPositionMs)
                .put("watched_ms", watchedMs)
                .put("unique_watched_ms", uniqueWatchedMs)
                .put("replayed_ms", replayedMs)
                .put("foreground_watch_ms", foregroundWatchMs)
                .put("background_play_ms", backgroundPlayMs)
                .putNullable("startup_ms", firstFrameElapsedMs?.let { max(0, it - startedElapsedMs) }))
            .put("interaction", JSONObject()
                .put("play_count", playCount)
                .put("pause_count", pauseCount)
                .put("resume_count", resumeCount)
                .put("seek_count", seekCount)
                .put("seek_forward_ms", seekForwardMs)
                .put("seek_backward_ms", seekBackwardMs)
                .put("fullscreen_count", fullscreenCount)
                .put("pip_count", pipCount)
                .put("cast_count", castCount))
            .put("buffering", JSONObject()
                .put("count", bufferCount)
                .put("total_ms", bufferTotalMs)
                .put("longest_ms", longestBufferMs))
            .put("quality", JSONObject()
                .putNullable("initial", initialQuality)
                .putNullable("final", finalQuality)
                .put("change_count", qualityChangeCount)
                .putNullable("average_bitrate_kbps", averageBitrateKbps)
                .putNullable("bytes_transferred", bytesTransferred)
                .putNullable("dropped_frames", droppedFrames)
                .putNullable("rendered_frames", renderedFrames)
                .putNullable("video_codec", videoCodec)
                .putNullable("audio_codec", audioCodec)
                .putNullable("stream_format", streamFormat))
            .put("tracks", JSONObject()
                .putNullable("audio_language", audioLanguage)
                .put("subtitle_enabled", subtitleEnabled)
                .putNullable("subtitle_language", subtitleLanguage)
                .put("playback_speed", playbackSpeed))
            .put("result", JSONObject()
                .put("completed", completed)
                .put("completion_percent", (completionPercent * 100.0).roundToLong() / 100.0)
                .put("milestones", milestones)
                .put("error_count", errorCount))
            .put("context", context.toJson())

        return PlaybackSummary(sessionId, revision, state, payload)
    }

    private fun accountElapsed(now: Long) {
        val elapsed = max(0, now - lastAccountingMs)
        if (playing && !buffering && elapsed > 0) {
            watchedMs += elapsed
            if (foreground) foregroundWatchMs += elapsed else backgroundPlayMs += elapsed
        }
        lastAccountingMs = now
    }

    private fun finishBuffer(now: Long) {
        val start = bufferStartedMs ?: return
        val value = max(0, now - start)
        if (currentBufferCounts) {
            bufferTotalMs += value
            longestBufferMs = max(longestBufferMs, value)
        }
        bufferStartedMs = null
        currentBufferCounts = false
    }

    private fun recordPosition(newPositionMs: Long, now: Long) {
        val previousPosition = lastSamplePositionMs
        val previousElapsed = lastSampleElapsedMs
        if (playing && !buffering && previousPosition != null && previousElapsed != null) {
            val mediaDelta = newPositionMs - previousPosition
            val elapsed = max(0, now - previousElapsed)
            val allowedDelta = max(2_500, (elapsed * max(1.0, playbackSpeed) + 2_000).roundToLong())
            if (mediaDelta > 0 && mediaDelta <= allowedDelta) {
                watchedRanges += WatchedRange(previousPosition, newPositionMs)
            }
        }
        positionMs = newPositionMs
        maxPositionMs = max(maxPositionMs, newPositionMs)
        lastSamplePositionMs = newPositionMs
        lastSampleElapsedMs = now
    }

    private fun mergedRangeDuration(): Long {
        val sorted = watchedRanges.filter { it.upper > it.lower }.sortedBy { it.lower }
        if (sorted.isEmpty()) return if (durationMs > 0) min(watchedMs, durationMs) else watchedMs
        var active = sorted.first().copy()
        var total = 0L
        sorted.drop(1).forEach { range ->
            if (range.lower <= active.upper + 1_000) active.upper = max(active.upper, range.upper)
            else {
                total += active.upper - active.lower
                active = range.copy()
            }
        }
        total += active.upper - active.lower
        return if (durationMs > 0) min(total, durationMs) else total
    }
}
