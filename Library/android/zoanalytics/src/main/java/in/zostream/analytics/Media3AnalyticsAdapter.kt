package `in`.zostream.analytics

import android.os.Handler
import android.os.Looper
import androidx.media3.common.C
import androidx.media3.common.PlaybackException
import androidx.media3.common.PlaybackParameters
import androidx.media3.common.Player
import androidx.media3.common.VideoSize
import java.io.Closeable
import kotlin.math.max

class Media3AnalyticsAdapter(
    private val player: Player,
    private val analytics: PlaybackAnalyticsSession,
    private val sampleIntervalMs: Long = 1_000,
) : Player.Listener, Closeable {
    private val handler = Handler(Looper.getMainLooper())
    private var closed = false
    private var sawFirstFrame = false
    private var buffering = false

    private val sampler = object : Runnable {
        override fun run() {
            if (closed) return
            sample()
            handler.postDelayed(this, max(500, sampleIntervalMs))
        }
    }

    init {
        player.addListener(this)
        handler.post(sampler)
        handlePlaybackState(player.playbackState)
        handlePlaying(player.isPlaying)
    }

    override fun onIsPlayingChanged(isPlaying: Boolean) {
        handlePlaying(isPlaying)
    }

    override fun onPlaybackStateChanged(playbackState: Int) {
        handlePlaybackState(playbackState)
    }

    override fun onPlayerError(error: PlaybackException) {
        analytics.recordError()
    }

    override fun onPositionDiscontinuity(
        oldPosition: Player.PositionInfo,
        newPosition: Player.PositionInfo,
        reason: Int,
    ) {
        if (reason == Player.DISCONTINUITY_REASON_SEEK ||
            reason == Player.DISCONTINUITY_REASON_SEEK_ADJUSTMENT
        ) {
            analytics.recordSeek(oldPosition.positionMs, newPosition.positionMs)
        }
    }

    override fun onPlaybackParametersChanged(playbackParameters: PlaybackParameters) {
        analytics.setTracks(playbackSpeed = playbackParameters.speed.toDouble())
    }

    override fun onVideoSizeChanged(videoSize: VideoSize) {
        if (videoSize.height > 0) analytics.recordQuality(label = "${videoSize.height}p")
    }

    fun setForeground(foreground: Boolean) = analytics.setForeground(foreground)
    fun recordFullscreenEntered() = analytics.recordFullscreenEntered()
    fun recordPictureInPictureStarted() = analytics.recordPictureInPictureStarted()
    fun recordCastStarted() = analytics.recordCastStarted()

    @JvmOverloads
    fun recordSubtitle(enabled: Boolean, language: String? = null) =
        analytics.setTracks(subtitleEnabled = enabled, subtitleLanguage = language)

    fun recordAudioLanguage(language: String?) = analytics.setTracks(audioLanguage = language)

    override fun close() {
        if (closed) return
        closed = true
        handler.removeCallbacks(sampler)
        player.removeListener(this)
    }

    private fun handlePlaying(isPlaying: Boolean) {
        if (isPlaying) analytics.recordPlay(player.currentPosition)
        else if (player.playbackState != Player.STATE_BUFFERING) {
            analytics.recordPause(player.currentPosition)
        }
    }

    private fun handlePlaybackState(state: Int) {
        when (state) {
            Player.STATE_BUFFERING -> if (!buffering) {
                buffering = true
                analytics.recordBufferingStarted(player.currentPosition)
            }
            Player.STATE_READY -> if (buffering) {
                buffering = false
                analytics.recordBufferingEnded(player.currentPosition)
            }
            Player.STATE_ENDED -> {
                if (buffering) {
                    buffering = false
                    analytics.recordBufferingEnded(player.currentPosition)
                }
                analytics.recordNaturalEnd(player.currentPosition, safeDuration())
            }
        }
    }

    private fun sample() {
        val position = max(0, player.currentPosition)
        if (!sawFirstFrame && player.isPlaying && position > 0) {
            sawFirstFrame = true
            analytics.recordFirstFrame(position)
        }
        analytics.tick(position, safeDuration())
    }

    private fun safeDuration(): Long = player.duration.takeIf { it != C.TIME_UNSET && it > 0 } ?: 0
}
