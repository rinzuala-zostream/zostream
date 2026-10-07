import type { PlaybackSummary } from './models.js'
import { PlaybackAnalyticsSession } from './playback-session.js'

export interface ShakaVariantTrackLike {
  active?: boolean; height?: number; bandwidth?: number; language?: string
  videoCodec?: string; audioCodec?: string
}
export interface ShakaStatsLike {
  estimatedBandwidth?: number; bytesDownloaded?: number
  droppedFrames?: number; decodedFrames?: number
}
export interface ShakaPlayerLike {
  addEventListener(name: string, listener: EventListener): void
  removeEventListener(name: string, listener: EventListener): void
  getVariantTracks(): ShakaVariantTrackLike[]
  getStats(): ShakaStatsLike
}
export interface PlayerAdapterOptions {
  mediaPlayer?: ShakaPlayerLike
  streamFormat?: string
  checkpointIntervalMs?: number
  onCheckpoint?: (summary: PlaybackSummary) => void
}

const millis = (seconds: number) => Math.max(0, Math.round((Number.isFinite(seconds) ? seconds : 0) * 1_000))

export class HTMLVideoAnalyticsAdapter {
  private removers: Array<() => void> = []
  private tickTimer: ReturnType<typeof setInterval> | undefined
  private checkpointTimer: ReturnType<typeof setInterval> | undefined
  private seekFrom: number | null = null
  private firstFrame = false
  private destroyed = false

  constructor(
    private readonly video: HTMLVideoElement,
    readonly analytics: PlaybackAnalyticsSession,
    private readonly options: PlayerAdapterOptions = {},
  ) {
    this.observeVideo(); this.observePlayer()
    this.tickTimer = setInterval(() => this.tick(), 1_000)
    if (options.onCheckpoint) {
      this.checkpointTimer = setInterval(
        () => options.onCheckpoint?.(analytics.checkpoint()),
        Math.max(10_000, options.checkpointIntervalMs ?? 30_000),
      )
    }
  }

  recordAudioLanguage(language: string | null): void { this.analytics.setTracks({ audioLanguage: language }) }
  recordSubtitle(enabled: boolean, language: string | null = null): void { this.analytics.setTracks({ subtitleEnabled: enabled, subtitleLanguage: language }) }
  recordFullscreenEntered(): void { this.analytics.recordFullscreenEntered() }
  recordPictureInPictureStarted(): void { this.analytics.recordPictureInPictureStarted() }
  recordCastStarted(): void { this.analytics.recordCastStarted() }
  setForeground(value: boolean): void { this.analytics.setForeground(value) }
  destroy(): void {
    if (this.destroyed) return; this.destroyed = true
    if (this.tickTimer !== undefined) clearInterval(this.tickTimer)
    if (this.checkpointTimer !== undefined) clearInterval(this.checkpointTimer)
    this.removers.splice(0).forEach((remove) => remove())
  }

  private observeVideo(): void {
    this.listen('play', () => this.analytics.recordPlay(millis(this.video.currentTime)))
    this.listen('pause', () => { if (!this.video.ended) this.analytics.recordPause(millis(this.video.currentTime)) })
    this.listen('waiting', () => this.analytics.recordBufferingStarted(millis(this.video.currentTime)))
    this.listen('stalled', () => this.analytics.recordBufferingStarted(millis(this.video.currentTime)))
    this.listen('playing', () => { this.recordFirstFrame(); this.analytics.recordBufferingEnded(millis(this.video.currentTime)) })
    this.listen('canplay', () => this.analytics.recordBufferingEnded(millis(this.video.currentTime)))
    this.listen('loadeddata', () => this.recordFirstFrame())
    this.listen('seeking', () => { if (this.seekFrom === null) this.seekFrom = millis(this.video.currentTime) })
    this.listen('seeked', () => {
      const to = millis(this.video.currentTime); if (this.seekFrom !== null) this.analytics.recordSeek(this.seekFrom, to)
      this.seekFrom = null; this.analytics.recordBufferingEnded(to)
    })
    this.listen('ratechange', () => this.analytics.setTracks({ playbackSpeed: this.video.playbackRate }))
    this.listen('ended', () => this.analytics.recordNaturalEnd(millis(this.video.currentTime), millis(this.video.duration)))
    this.listen('error', () => this.analytics.recordError())
    this.listen('timeupdate', () => this.tick())
  }
  private observePlayer(): void {
    const player = this.options.mediaPlayer; if (!player) return
    const quality = () => this.recordQuality()
    player.addEventListener('variantchanged', quality); player.addEventListener('adaptation', quality)
    this.removers.push(() => { player.removeEventListener('variantchanged', quality); player.removeEventListener('adaptation', quality) })
    this.recordQuality()
  }
  private listen(name: string, callback: () => void): void {
    this.video.addEventListener(name, callback); this.removers.push(() => this.video.removeEventListener(name, callback))
  }
  private recordFirstFrame(): void {
    if (this.firstFrame || this.video.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) return
    this.firstFrame = true; this.analytics.recordFirstFrame(millis(this.video.currentTime))
  }
  private tick(): void {
    if (this.destroyed) return
    this.analytics.tick(millis(this.video.currentTime), millis(this.video.duration)); this.recordQuality()
  }
  private recordQuality(): void {
    const player = this.options.mediaPlayer; if (!player) return
    const active = player.getVariantTracks().find((track) => track.active); const stats = player.getStats()
    this.analytics.recordQuality({
      label: active?.height ? `${active.height}p` : null,
      averageBitrateKbps: active?.bandwidth ? active.bandwidth / 1_000 : stats.estimatedBandwidth ? stats.estimatedBandwidth / 1_000 : null,
      bytesTransferred: stats.bytesDownloaded ?? null, droppedFrames: stats.droppedFrames ?? null,
      renderedFrames: stats.decodedFrames ?? null, videoCodec: active?.videoCodec ?? null,
      audioCodec: active?.audioCodec ?? null, streamFormat: this.options.streamFormat ?? null,
    })
    if (active?.language) this.recordAudioLanguage(active.language)
  }
}
