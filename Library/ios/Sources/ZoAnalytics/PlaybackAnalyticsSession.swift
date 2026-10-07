import Foundation

public final class PlaybackAnalyticsSession: @unchecked Sendable {
    private struct Range: Sendable {
        var lower: Int64
        var upper: Int64
    }

    private let lock = NSLock()
    private let uptime: @Sendable () -> TimeInterval
    private let now: @Sendable () -> Date

    public let sessionId: String
    public let content: PlaybackContent
    public let context: AnalyticsContext

    private let startedAt: Date
    private let startedUptime: TimeInterval
    private var firstFrameUptime: TimeInterval?
    private var lastAccountingUptime: TimeInterval
    private var bufferStartedUptime: TimeInterval?
    private var currentBufferMs: Int64 = 0
    private var currentBufferCounts = false

    private var revision = 0
    private var isPlaying = false
    private var isBuffering = false
    private var isForeground = true
    private var naturalEnd = false
    private var finished = false

    private var durationMs: Int64 = 0
    private var positionMs: Int64 = 0
    private var maxPositionMs: Int64 = 0
    private var lastSamplePositionMs: Int64?
    private var lastSampleUptime: TimeInterval?
    private var watchedMs: Int64 = 0
    private var foregroundWatchMs: Int64 = 0
    private var backgroundPlayMs: Int64 = 0
    private var watchedRanges: [Range] = []

    private var playCount = 0
    private var pauseCount = 0
    private var resumeCount = 0
    private var seekCount = 0
    private var seekForwardMs: Int64 = 0
    private var seekBackwardMs: Int64 = 0
    private var fullscreenCount = 0
    private var pipCount = 0
    private var castCount = 0

    private var bufferCount = 0
    private var bufferTotalMs: Int64 = 0
    private var longestBufferMs: Int64 = 0
    private var errorCount = 0

    private var quality = PlaybackQuality()
    private var tracks = PlaybackTracks()

    public init(
        sessionId: String = UUID().uuidString.lowercased(),
        content: PlaybackContent,
        context: AnalyticsContext,
        now: @escaping @Sendable () -> Date = Date.init,
        uptime: @escaping @Sendable () -> TimeInterval = { ProcessInfo.processInfo.systemUptime }
    ) {
        self.sessionId = sessionId
        self.content = content
        self.context = context
        self.now = now
        self.uptime = uptime
        let initialUptime = uptime()
        self.startedAt = now()
        self.startedUptime = initialUptime
        self.lastAccountingUptime = initialUptime
    }

    public func recordFirstFrame(positionMs: Int64 = 0) {
        withLock {
            guard !finished else { return }
            let instant = uptime()
            if firstFrameUptime == nil { firstFrameUptime = instant }
            recordPosition(max(0, positionMs), at: instant)
        }
    }

    public func recordPlay(positionMs: Int64) {
        withLock {
            guard !finished else { return }
            let instant = uptime()
            accountElapsed(at: instant)
            if !isPlaying {
                if playCount == 0 { playCount = 1 } else { resumeCount += 1 }
            }
            isPlaying = true
            recordPosition(max(0, positionMs), at: instant)
        }
    }

    public func recordPause(positionMs: Int64) {
        withLock {
            guard !finished else { return }
            let instant = uptime()
            accountElapsed(at: instant)
            if isPlaying { pauseCount += 1 }
            isPlaying = false
            recordPosition(max(0, positionMs), at: instant)
        }
    }

    public func recordSeek(fromMs: Int64, toMs: Int64) {
        withLock {
            guard !finished else { return }
            let instant = uptime()
            accountElapsed(at: instant)
            let delta = toMs - fromMs
            seekCount += 1
            if delta >= 0 { seekForwardMs += delta } else { seekBackwardMs += -delta }
            positionMs = max(0, toMs)
            maxPositionMs = max(maxPositionMs, positionMs)
            lastSamplePositionMs = positionMs
            lastSampleUptime = instant
        }
    }

    public func recordBufferingStarted(positionMs: Int64) {
        withLock {
            guard !finished, !isBuffering else { return }
            let instant = uptime()
            accountElapsed(at: instant)
            isBuffering = true
            bufferStartedUptime = instant
            currentBufferMs = 0
            currentBufferCounts = firstFrameUptime != nil
            if currentBufferCounts { bufferCount += 1 }
            recordPosition(max(0, positionMs), at: instant)
        }
    }

    public func recordBufferingEnded(positionMs: Int64) {
        withLock {
            guard !finished, isBuffering else { return }
            let instant = uptime()
            finishBuffer(at: instant)
            isBuffering = false
            lastAccountingUptime = instant
            recordPosition(max(0, positionMs), at: instant)
        }
    }

    public func tick(positionMs: Int64, durationMs: Int64) {
        withLock {
            guard !finished else { return }
            let instant = uptime()
            accountElapsed(at: instant)
            self.durationMs = max(self.durationMs, max(0, durationMs))
            recordPosition(max(0, positionMs), at: instant)
        }
    }

    public func recordNaturalEnd(positionMs: Int64, durationMs: Int64) {
        withLock {
            guard !finished else { return }
            let instant = uptime()
            accountElapsed(at: instant)
            naturalEnd = true
            isPlaying = false
            self.durationMs = max(self.durationMs, max(0, durationMs))
            recordPosition(max(0, positionMs), at: instant)
        }
    }

    public func recordError() {
        withLock { if !finished { errorCount += 1 } }
    }

    public func setForeground(_ foreground: Bool) {
        withLock {
            let instant = uptime()
            accountElapsed(at: instant)
            isForeground = foreground
        }
    }

    public func recordFullscreenEntered() { withLock { fullscreenCount += 1 } }
    public func recordPictureInPictureStarted() { withLock { pipCount += 1 } }
    public func recordCastStarted() { withLock { castCount += 1 } }

    public func setTracks(
        audioLanguage: String? = nil,
        subtitleEnabled: Bool? = nil,
        subtitleLanguage: String? = nil,
        playbackSpeed: Double? = nil
    ) {
        withLock {
            if let audioLanguage { tracks.audioLanguage = audioLanguage }
            if let subtitleEnabled { tracks.subtitleEnabled = subtitleEnabled }
            if let subtitleLanguage { tracks.subtitleLanguage = subtitleLanguage }
            if let playbackSpeed { tracks.playbackSpeed = max(0.25, min(4, playbackSpeed)) }
        }
    }

    public func recordQuality(
        label: String?,
        averageBitrateKbps: Int? = nil,
        droppedFrames: Int? = nil,
        renderedFrames: Int? = nil,
        videoCodec: String? = nil,
        audioCodec: String? = nil,
        streamFormat: String? = nil,
        bytesTransferred: Int64? = nil
    ) {
        withLock {
            if let label, !label.isEmpty {
                if quality.initial == nil { quality.initial = label }
                if let previous = quality.final, previous != label { quality.changeCount += 1 }
                quality.final = label
            }
            if let averageBitrateKbps { quality.averageBitrateKbps = max(0, averageBitrateKbps) }
            if let bytesTransferred {
                quality.bytesTransferred = max(quality.bytesTransferred ?? 0, bytesTransferred)
            }
            if let droppedFrames { quality.droppedFrames = max(0, droppedFrames) }
            if let renderedFrames { quality.renderedFrames = max(0, renderedFrames) }
            if let videoCodec { quality.videoCodec = videoCodec }
            if let audioCodec { quality.audioCodec = audioCodec }
            if let streamFormat { quality.streamFormat = streamFormat }
        }
    }

    public func checkpoint() -> PlaybackSummary {
        makeSummary(state: .checkpoint, endReason: nil, markFinished: false)
    }

    public func finish(reason: PlaybackEndReason) -> PlaybackSummary {
        makeSummary(state: .final, endReason: reason, markFinished: true)
    }

    private func makeSummary(
        state uploadState: PlaybackUploadState,
        endReason: PlaybackEndReason?,
        markFinished: Bool
    ) -> PlaybackSummary {
        withLock {
            let instant = uptime()
            accountElapsed(at: instant)
            if isBuffering { finishBuffer(at: instant) }
            if markFinished {
                isPlaying = false
                isBuffering = false
                finished = true
            }
            revision += 1

            let uniqueMs = mergedRangeDuration()
            let replayedMs = max(0, watchedMs - uniqueMs)
            let completionPercent = durationMs > 0
                ? min(100, (Double(uniqueMs) / Double(durationMs)) * 100)
                : 0
            let completed = naturalEnd || completionPercent >= 90
            let milestones = [25, 50, 75, 90].filter { completionPercent >= Double($0) }
            let startupMs = firstFrameUptime.map {
                Int64(max(0, ($0 - startedUptime) * 1_000).rounded())
            }

            let payload = PlaybackPayload(
                schemaVersion: 1,
                revision: revision,
                state: uploadState,
                startedAt: startedAt,
                endedAt: uploadState == .final ? now() : nil,
                endReason: uploadState == .final
                    ? (completed ? .completed : (endReason ?? .unknown))
                    : nil,
                content: content,
                timing: PlaybackTiming(
                    durationMs: durationMs,
                    watchPositionMs: positionMs,
                    maxPositionMs: maxPositionMs,
                    watchedMs: watchedMs,
                    uniqueWatchedMs: uniqueMs,
                    replayedMs: replayedMs,
                    foregroundWatchMs: foregroundWatchMs,
                    backgroundPlayMs: backgroundPlayMs,
                    startupMs: startupMs
                ),
                interaction: PlaybackInteraction(
                    playCount: playCount,
                    pauseCount: pauseCount,
                    resumeCount: resumeCount,
                    seekCount: seekCount,
                    seekForwardMs: seekForwardMs,
                    seekBackwardMs: seekBackwardMs,
                    fullscreenCount: fullscreenCount,
                    pipCount: pipCount,
                    castCount: castCount
                ),
                buffering: PlaybackBuffering(
                    count: bufferCount,
                    totalMs: bufferTotalMs,
                    longestMs: longestBufferMs
                ),
                quality: quality,
                tracks: tracks,
                result: PlaybackResult(
                    completed: completed,
                    completionPercent: (completionPercent * 100).rounded() / 100,
                    milestones: milestones,
                    errorCount: errorCount
                ),
                context: context
            )
            return PlaybackSummary(sessionId: sessionId, payload: payload)
        }
    }

    private func accountElapsed(at instant: TimeInterval) {
        let elapsedMs = Int64(max(0, (instant - lastAccountingUptime) * 1_000).rounded())
        if isPlaying && !isBuffering && elapsedMs > 0 {
            watchedMs += elapsedMs
            if isForeground { foregroundWatchMs += elapsedMs }
            else { backgroundPlayMs += elapsedMs }
        }
        lastAccountingUptime = instant
    }

    private func finishBuffer(at instant: TimeInterval) {
        guard let began = bufferStartedUptime else { return }
        currentBufferMs = Int64(max(0, (instant - began) * 1_000).rounded())
        if currentBufferCounts {
            bufferTotalMs += currentBufferMs
            longestBufferMs = max(longestBufferMs, currentBufferMs)
        }
        bufferStartedUptime = nil
        currentBufferCounts = false
    }

    private func recordPosition(_ newPositionMs: Int64, at instant: TimeInterval) {
        if isPlaying && !isBuffering,
           let previousPosition = lastSamplePositionMs,
           let previousUptime = lastSampleUptime {
            let mediaDelta = newPositionMs - previousPosition
            let elapsedMs = Int64(max(0, (instant - previousUptime) * 1_000).rounded())
            let allowedDelta = max(2_500, Int64(Double(elapsedMs) * max(1, tracks.playbackSpeed) + 2_000))
            if mediaDelta > 0 && mediaDelta <= allowedDelta {
                watchedRanges.append(Range(lower: previousPosition, upper: newPositionMs))
            }
        }
        positionMs = newPositionMs
        maxPositionMs = max(maxPositionMs, newPositionMs)
        lastSamplePositionMs = newPositionMs
        lastSampleUptime = instant
    }

    private func mergedRangeDuration() -> Int64 {
        let sorted = watchedRanges
            .filter { $0.upper > $0.lower }
            .sorted { $0.lower < $1.lower }
        guard var active = sorted.first else { return min(watchedMs, durationMs) }
        var total: Int64 = 0
        for range in sorted.dropFirst() {
            if range.lower <= active.upper + 1_000 {
                active.upper = max(active.upper, range.upper)
            } else {
                total += active.upper - active.lower
                active = range
            }
        }
        total += active.upper - active.lower
        if durationMs > 0 { return min(total, durationMs) }
        return total
    }

    private func withLock<T>(_ body: () -> T) -> T {
        lock.lock()
        defer { lock.unlock() }
        return body()
    }
}
