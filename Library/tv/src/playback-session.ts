import { createUuid } from './models.js'
import type {
  AnalyticsContext, PlaybackContent, PlaybackEndReason,
  PlaybackSummary, PlaybackSummaryPayload, PlaybackUploadState,
} from './models.js'

export interface AnalyticsClock { now(): number; uptime(): number }
type WatchedRange = { lower: number; upper: number }

const defaultClock: AnalyticsClock = {
  now: () => Date.now(),
  uptime: () => typeof performance === 'undefined' ? Date.now() : performance.now(),
}
const ms = (value: number) => Math.min(86_400_000, Math.max(0, Math.round(Number.isFinite(value) ? value : 0)))

export class PlaybackAnalyticsSession {
  readonly sessionId: string
  readonly content: PlaybackContent
  readonly context: AnalyticsContext
  private readonly clock: AnalyticsClock
  private readonly startedAt: number
  private readonly startedUptime: number
  private lastAccounting: number
  private firstFrame: number | null = null
  private bufferStarted: number | null = null
  private currentBufferCounts = false
  private lastSamplePosition: number | null = null
  private lastSampleUptime: number | null = null
  private revision = 0
  private playing = false
  private buffering = false
  private foreground = true
  private naturalEnd = false
  private finished = false
  private duration = 0
  private position = 0
  private maxPosition = 0
  private watched = 0
  private foregroundWatch = 0
  private backgroundPlay = 0
  private ranges: WatchedRange[] = []
  private playCount = 0
  private pauseCount = 0
  private resumeCount = 0
  private seekCount = 0
  private seekForward = 0
  private seekBackward = 0
  private fullscreenCount = 0
  private pipCount = 0
  private castCount = 0
  private bufferCount = 0
  private bufferTotal = 0
  private longestBuffer = 0
  private errorCount = 0
  private initialQuality: string | null = null
  private finalQuality: string | null = null
  private qualityChanges = 0
  private averageBitrate: number | null = null
  private transferred: number | null = null
  private dropped: number | null = null
  private rendered: number | null = null
  private videoCodec: string | null = null
  private audioCodec: string | null = null
  private format: string | null = null
  private audioLanguage: string | null = null
  private subtitleEnabled = false
  private subtitleLanguage: string | null = null
  private speed = 1

  constructor(input: {
    content: PlaybackContent; context: AnalyticsContext
    sessionId?: string; clock?: AnalyticsClock
  }) {
    this.sessionId = input.sessionId ?? createUuid()
    this.content = input.content
    this.context = input.context
    this.clock = input.clock ?? defaultClock
    this.startedAt = this.clock.now()
    this.startedUptime = this.clock.uptime()
    this.lastAccounting = this.startedUptime
  }

  recordFirstFrame(positionMs = 0): void {
    if (this.finished) return
    const now = this.clock.uptime()
    if (this.firstFrame === null) this.firstFrame = now
    this.recordPosition(ms(positionMs), now)
  }
  recordPlay(positionMs: number): void {
    if (this.finished) return
    const now = this.clock.uptime(); this.account(now)
    if (!this.playing) this.playCount === 0 ? this.playCount += 1 : this.resumeCount += 1
    this.playing = true; this.recordPosition(ms(positionMs), now)
  }
  recordPause(positionMs: number): void {
    if (this.finished) return
    const now = this.clock.uptime(); this.account(now)
    if (this.playing) this.pauseCount += 1
    this.playing = false; this.recordPosition(ms(positionMs), now)
  }
  recordSeek(fromMs: number, toMs: number): void {
    if (this.finished) return
    const now = this.clock.uptime(); this.account(now)
    const from = ms(fromMs); const to = ms(toMs); const delta = to - from
    this.seekCount += 1
    delta >= 0 ? this.seekForward += delta : this.seekBackward += -delta
    this.position = to; this.maxPosition = Math.max(this.maxPosition, to)
    this.lastSamplePosition = to; this.lastSampleUptime = now
  }
  recordBufferingStarted(positionMs: number): void {
    if (this.finished || this.buffering) return
    const now = this.clock.uptime(); this.account(now)
    this.buffering = true; this.bufferStarted = now
    this.currentBufferCounts = this.firstFrame !== null
    if (this.currentBufferCounts) this.bufferCount += 1
    this.recordPosition(ms(positionMs), now)
  }
  recordBufferingEnded(positionMs: number): void {
    if (this.finished || !this.buffering) return
    const now = this.clock.uptime(); this.finishBuffer(now)
    this.buffering = false; this.lastAccounting = now
    this.recordPosition(ms(positionMs), now)
  }
  tick(positionMs: number, durationMs: number): void {
    if (this.finished) return
    const now = this.clock.uptime(); this.account(now)
    this.duration = Math.max(this.duration, ms(durationMs))
    this.recordPosition(ms(positionMs), now)
  }
  recordNaturalEnd(positionMs: number, durationMs: number): void {
    if (this.finished) return
    const now = this.clock.uptime(); this.account(now)
    this.naturalEnd = true; this.playing = false
    this.duration = Math.max(this.duration, ms(durationMs))
    this.recordPosition(ms(positionMs), now)
  }
  recordError(): void { if (!this.finished) this.errorCount += 1 }
  setForeground(value: boolean): void { this.account(this.clock.uptime()); this.foreground = value }
  recordFullscreenEntered(): void { this.fullscreenCount += 1 }
  recordPictureInPictureStarted(): void { this.pipCount += 1 }
  recordCastStarted(): void { this.castCount += 1 }

  setTracks(input: {
    audioLanguage?: string | null; subtitleEnabled?: boolean
    subtitleLanguage?: string | null; playbackSpeed?: number
  }): void {
    if (input.audioLanguage !== undefined) this.audioLanguage = input.audioLanguage
    if (input.subtitleEnabled !== undefined) this.subtitleEnabled = input.subtitleEnabled
    if (input.subtitleLanguage !== undefined) this.subtitleLanguage = input.subtitleLanguage
    if (input.playbackSpeed !== undefined) this.speed = Math.max(.25, Math.min(4, input.playbackSpeed))
  }

  recordQuality(input: {
    label?: string | null; averageBitrateKbps?: number | null
    bytesTransferred?: number | null; droppedFrames?: number | null
    renderedFrames?: number | null; videoCodec?: string | null
    audioCodec?: string | null; streamFormat?: string | null
  }): void {
    if (input.label) {
      if (this.initialQuality === null) this.initialQuality = input.label
      if (this.finalQuality !== null && this.finalQuality !== input.label) this.qualityChanges += 1
      this.finalQuality = input.label
    }
    if (typeof input.averageBitrateKbps === 'number') this.averageBitrate = Math.max(0, Math.round(input.averageBitrateKbps))
    if (typeof input.bytesTransferred === 'number') this.transferred = Math.max(this.transferred ?? 0, Math.round(input.bytesTransferred))
    if (typeof input.droppedFrames === 'number') this.dropped = Math.max(0, Math.round(input.droppedFrames))
    if (typeof input.renderedFrames === 'number') this.rendered = Math.max(0, Math.round(input.renderedFrames))
    if (input.videoCodec !== undefined) this.videoCodec = input.videoCodec
    if (input.audioCodec !== undefined) this.audioCodec = input.audioCodec
    if (input.streamFormat !== undefined) this.format = input.streamFormat
  }

  checkpoint(): PlaybackSummary { return this.summary('checkpoint', null, false) }
  finish(reason: PlaybackEndReason): PlaybackSummary { return this.summary('final', reason, true) }

  private summary(state: PlaybackUploadState, reason: PlaybackEndReason | null, finish: boolean): PlaybackSummary {
    const now = this.clock.uptime(); this.account(now)
    if (this.buffering) this.finishBuffer(now)
    if (finish) { this.playing = false; this.buffering = false; this.finished = true }
    this.revision += 1
    const unique = this.mergedRangeDuration()
    const completion = this.duration > 0 ? Math.min(100, unique / this.duration * 100) : 0
    const completed = this.naturalEnd || completion >= 90
    const milestones = [25, 50, 75, 90].filter((value) => completion >= value) as Array<25 | 50 | 75 | 90>
    const payload: PlaybackSummaryPayload = {
      schema_version: 1, revision: this.revision, state,
      started_at: new Date(this.startedAt).toISOString(),
      ended_at: state === 'final' ? new Date(this.clock.now()).toISOString() : null,
      end_reason: state === 'final' ? (completed ? 'completed' : reason ?? 'unknown') : null,
      content: this.content,
      timing: {
        duration_ms: ms(this.duration), watch_position_ms: ms(this.position), max_position_ms: ms(this.maxPosition),
        watched_ms: ms(this.watched), unique_watched_ms: ms(unique), replayed_ms: ms(this.watched - unique),
        foreground_watch_ms: ms(this.foregroundWatch), background_play_ms: ms(this.backgroundPlay),
        startup_ms: this.firstFrame === null ? null : ms(this.firstFrame - this.startedUptime),
      },
      interaction: {
        play_count: this.playCount, pause_count: this.pauseCount, resume_count: this.resumeCount,
        seek_count: this.seekCount, seek_forward_ms: ms(this.seekForward), seek_backward_ms: ms(this.seekBackward),
        fullscreen_count: this.fullscreenCount, pip_count: this.pipCount, cast_count: this.castCount,
      },
      buffering: { count: this.bufferCount, total_ms: ms(this.bufferTotal), longest_ms: ms(this.longestBuffer) },
      quality: {
        initial: this.initialQuality, final: this.finalQuality, change_count: this.qualityChanges,
        average_bitrate_kbps: this.averageBitrate, bytes_transferred: this.transferred,
        dropped_frames: this.dropped, rendered_frames: this.rendered,
        video_codec: this.videoCodec, audio_codec: this.audioCodec, stream_format: this.format,
      },
      tracks: { audio_language: this.audioLanguage, subtitle_enabled: this.subtitleEnabled, subtitle_language: this.subtitleLanguage, playback_speed: this.speed },
      result: { completed, completion_percent: Math.round(completion * 100) / 100, milestones, error_count: this.errorCount },
      context: this.context,
    }
    return { sessionId: this.sessionId, revision: this.revision, state, payload }
  }

  private account(now: number): void {
    const elapsed = Math.max(0, Math.round(now - this.lastAccounting))
    if (this.playing && !this.buffering) {
      this.watched += elapsed
      this.foreground ? this.foregroundWatch += elapsed : this.backgroundPlay += elapsed
    }
    this.lastAccounting = now
  }
  private finishBuffer(now: number): void {
    if (this.bufferStarted === null) return
    const value = Math.max(0, Math.round(now - this.bufferStarted))
    if (this.currentBufferCounts) { this.bufferTotal += value; this.longestBuffer = Math.max(this.longestBuffer, value) }
    this.bufferStarted = null; this.currentBufferCounts = false
  }
  private recordPosition(next: number, now: number): void {
    if (this.playing && !this.buffering && this.lastSamplePosition !== null && this.lastSampleUptime !== null) {
      const mediaDelta = next - this.lastSamplePosition
      const elapsed = Math.max(0, now - this.lastSampleUptime)
      const allowed = Math.max(2_500, Math.round(elapsed * Math.max(1, this.speed) + 2_000))
      if (mediaDelta > 0 && mediaDelta <= allowed) this.ranges.push({ lower: this.lastSamplePosition, upper: next })
    }
    this.position = next; this.maxPosition = Math.max(this.maxPosition, next)
    this.lastSamplePosition = next; this.lastSampleUptime = now
  }
  private mergedRangeDuration(): number {
    const sorted = this.ranges.filter((range) => range.upper > range.lower).slice().sort((a, b) => a.lower - b.lower)
    const first = sorted[0]
    if (!first) return this.duration > 0 ? Math.min(this.watched, this.duration) : this.watched
    let active = { ...first }; let total = 0
    for (const range of sorted.slice(1)) {
      if (range.lower <= active.upper + 1_000) active.upper = Math.max(active.upper, range.upper)
      else { total += active.upper - active.lower; active = { ...range } }
    }
    total += active.upper - active.lower
    return this.duration > 0 ? Math.min(total, this.duration) : total
  }
}
