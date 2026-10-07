#if canImport(AVFoundation)
@preconcurrency import AVFoundation
import Foundation

@MainActor
public final class AVPlayerAnalyticsAdapter {
    private weak var player: AVPlayer?
    private let analytics: PlaybackAnalyticsSession
    private var timeObserver: Any?
    private var timeControlObservation: NSKeyValueObservation?
    private var currentItemObservation: NSKeyValueObservation?
    private var itemStatusObservation: NSKeyValueObservation?
    private var presentationSizeObservation: NSKeyValueObservation?
    private var endObserver: NSObjectProtocol?
    private var failureObserver: NSObjectProtocol?
    private var lastTimeControlStatus: AVPlayer.TimeControlStatus?
    private var sawFirstFrame = false

    public init(player: AVPlayer, analytics: PlaybackAnalyticsSession) {
        self.player = player
        self.analytics = analytics
        install(on: player)
    }

    deinit {
        if let timeObserver, let player { player.removeTimeObserver(timeObserver) }
        if let endObserver { NotificationCenter.default.removeObserver(endObserver) }
        if let failureObserver { NotificationCenter.default.removeObserver(failureObserver) }
        timeControlObservation?.invalidate()
        currentItemObservation?.invalidate()
        itemStatusObservation?.invalidate()
        presentationSizeObservation?.invalidate()
    }

    public func recordSeek(from seconds: Double, to secondsTo: Double) {
        analytics.recordSeek(
            fromMs: Self.milliseconds(seconds),
            toMs: Self.milliseconds(secondsTo)
        )
    }

    public func recordSubtitle(enabled: Bool, language: String?) {
        analytics.setTracks(subtitleEnabled: enabled, subtitleLanguage: language)
    }

    public func recordAudioLanguage(_ language: String?) {
        analytics.setTracks(audioLanguage: language)
    }

    public func setForeground(_ foreground: Bool) {
        analytics.setForeground(foreground)
    }

    public func recordPictureInPictureStarted() {
        analytics.recordPictureInPictureStarted()
    }

    public func recordFullscreenEntered() {
        analytics.recordFullscreenEntered()
    }

    public func recordAirPlayStarted() {
        analytics.recordCastStarted()
    }

    private func install(on player: AVPlayer) {
        timeControlObservation = player.observe(\.timeControlStatus, options: [.initial, .new]) {
            [weak self] player, _ in
            Task { @MainActor [weak self] in self?.handleTimeControl(player.timeControlStatus) }
        }
        currentItemObservation = player.observe(\.currentItem, options: [.initial, .new]) {
            [weak self] player, _ in
            Task { @MainActor [weak self] in self?.observeItem(player.currentItem) }
        }

        timeObserver = player.addPeriodicTimeObserver(
            forInterval: CMTime(seconds: 1, preferredTimescale: 1_000),
            queue: .main
        ) { [weak self] time in
            Task { @MainActor [weak self] in self?.sample(time: time) }
        }
    }

    private func observeItem(_ item: AVPlayerItem?) {
        itemStatusObservation?.invalidate()
        presentationSizeObservation?.invalidate()
        if let endObserver { NotificationCenter.default.removeObserver(endObserver) }
        if let failureObserver { NotificationCenter.default.removeObserver(failureObserver) }
        guard let item else { return }

        itemStatusObservation = item.observe(\.status, options: [.new]) { [weak self] item, _ in
            Task { @MainActor [weak self] in
                if item.status == .failed { self?.analytics.recordError() }
            }
        }
        presentationSizeObservation = item.observe(\.presentationSize, options: [.initial, .new]) {
            [weak self] item, _ in
            Task { @MainActor [weak self] in
                let height = Int(item.presentationSize.height.rounded())
                guard height > 0 else { return }
                self?.analytics.recordQuality(label: "\(height)p")
            }
        }
        endObserver = NotificationCenter.default.addObserver(
            forName: .AVPlayerItemDidPlayToEndTime,
            object: item,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in
                guard let self, let player = self.player else { return }
                self.analytics.recordNaturalEnd(
                    positionMs: Self.milliseconds(player.currentTime().seconds),
                    durationMs: Self.milliseconds(item.duration.seconds)
                )
            }
        }
        failureObserver = NotificationCenter.default.addObserver(
            forName: .AVPlayerItemFailedToPlayToEndTime,
            object: item,
            queue: .main
        ) { [weak self] _ in
            Task { @MainActor [weak self] in self?.analytics.recordError() }
        }
    }

    private func handleTimeControl(_ status: AVPlayer.TimeControlStatus) {
        guard let player else { return }
        let position = Self.milliseconds(player.currentTime().seconds)
        if lastTimeControlStatus == status { return }
        defer { lastTimeControlStatus = status }

        switch status {
        case .playing:
            if lastTimeControlStatus == .waitingToPlayAtSpecifiedRate {
                analytics.recordBufferingEnded(positionMs: position)
            }
            analytics.recordPlay(positionMs: position)
            analytics.setTracks(playbackSpeed: Double(player.rate))
        case .paused:
            analytics.recordPause(positionMs: position)
        case .waitingToPlayAtSpecifiedRate:
            analytics.recordBufferingStarted(positionMs: position)
        @unknown default:
            break
        }
    }

    private func sample(time: CMTime) {
        guard let player, let item = player.currentItem else { return }
        let position = Self.milliseconds(time.seconds)
        let duration = Self.milliseconds(item.duration.seconds)
        if !sawFirstFrame, player.timeControlStatus == .playing, position > 0 {
            sawFirstFrame = true
            analytics.recordFirstFrame(positionMs: position)
        }
        analytics.tick(positionMs: position, durationMs: duration)
        sampleAccessLog(item.accessLog())
    }

    private func sampleAccessLog(_ log: AVPlayerItemAccessLog?) {
        guard let events = log?.events, let event = events.last else { return }
        let bitrate = event.observedBitrate.isFinite && event.observedBitrate > 0
            ? Int((event.observedBitrate / 1_000).rounded())
            : nil
        let bytesTransferred = events.reduce(Int64(0)) { total, event in
            total + max(0, event.numberOfBytesTransferred)
        }
        analytics.recordQuality(
            label: nil,
            averageBitrateKbps: bitrate,
            droppedFrames: event.numberOfDroppedVideoFrames,
            renderedFrames: nil,
            streamFormat: "hls",
            bytesTransferred: bytesTransferred
        )
    }

    private static func milliseconds(_ seconds: Double) -> Int64 {
        guard seconds.isFinite, seconds > 0 else { return 0 }
        return Int64((seconds * 1_000).rounded())
    }
}
#endif
