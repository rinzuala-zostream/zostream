import Foundation

struct PendingPlayback: Codable, Sendable, Equatable {
    var ownerKey: String
    var summary: PlaybackSummary
    var queuedAt: Date
    var attemptCount: Int
    var nextAttemptAt: Date
}

struct PendingProductEvent: Codable, Sendable, Equatable {
    var ownerKey: String
    var event: ProductEvent
    var context: AnalyticsContext
    var queuedAt: Date
    var attemptCount: Int
    var nextAttemptAt: Date
}

struct PendingPlaybackError: Codable, Sendable, Equatable {
    var ownerKey: String
    var sessionId: String
    var event: PlaybackErrorEvent
    var platform: AnalyticsPlatform
    var queuedAt: Date
    var attemptCount: Int
    var nextAttemptAt: Date
}

private struct QueueDocument: Codable, Sendable {
    var playback: [PendingPlayback] = []
    var events: [PendingProductEvent] = []
    var errors: [PendingPlaybackError] = []

    enum CodingKeys: String, CodingKey { case playback, events, errors }

    init() {}

    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        playback = try container.decodeIfPresent([PendingPlayback].self, forKey: .playback) ?? []
        events = try container.decodeIfPresent([PendingProductEvent].self, forKey: .events) ?? []
        errors = try container.decodeIfPresent([PendingPlaybackError].self, forKey: .errors) ?? []
    }
}

actor AnalyticsQueue {
    private let fileURL: URL
    private let maxPendingSessions: Int
    private let retention: TimeInterval
    private var document: QueueDocument

    init(
        fileURL: URL,
        maxPendingSessions: Int,
        retentionDays: Int
    ) {
        self.fileURL = fileURL
        self.maxPendingSessions = maxPendingSessions
        self.retention = TimeInterval(retentionDays * 86_400)
        self.document = (try? Self.load(from: fileURL)) ?? QueueDocument()
    }

    func upsert(_ summary: PlaybackSummary, ownerKey: String, now: Date = Date()) throws {
        prune(now: now)
        let pending = PendingPlayback(
            ownerKey: ownerKey,
            summary: summary,
            queuedAt: now,
            attemptCount: 0,
            nextAttemptAt: now
        )
        if let index = document.playback.firstIndex(where: {
            $0.ownerKey == ownerKey && $0.summary.sessionId == summary.sessionId
        }) {
            guard document.playback[index].summary.payload.revision <= summary.payload.revision else { return }
            document.playback[index] = pending
        } else {
            document.playback.append(pending)
        }
        if document.playback.count > maxPendingSessions {
            document.playback.sort { $0.queuedAt < $1.queuedAt }
            document.playback.removeFirst(document.playback.count - maxPendingSessions)
        }
        try persist()
    }

    func enqueue(_ event: ProductEvent, context: AnalyticsContext, ownerKey: String, now: Date = Date()) throws {
        prune(now: now)
        guard !document.events.contains(where: { $0.event.eventId == event.eventId }) else { return }
        document.events.append(PendingProductEvent(
            ownerKey: ownerKey,
            event: event,
            context: context,
            queuedAt: now,
            attemptCount: 0,
            nextAttemptAt: now
        ))
        let eventLimit = maxPendingSessions * 10
        if document.events.count > eventLimit {
            document.events.sort { $0.queuedAt < $1.queuedAt }
            document.events.removeFirst(document.events.count - eventLimit)
        }
        try persist()
    }

    func enqueueError(_ event: PlaybackErrorEvent, sessionId: String, platform: AnalyticsPlatform, ownerKey: String, now: Date = Date()) throws {
        prune(now: now)
        guard !document.errors.contains(where: { $0.event.eventId == event.eventId }) else { return }
        document.errors.append(PendingPlaybackError(ownerKey: ownerKey, sessionId: sessionId, event: event, platform: platform, queuedAt: now, attemptCount: 0, nextAttemptAt: now))
        if document.errors.count > maxPendingSessions {
            document.errors.sort { $0.queuedAt < $1.queuedAt }
            document.errors.removeFirst(document.errors.count - maxPendingSessions)
        }
        try persist()
    }

    func duePlayback(ownerKey: String, limit: Int, now: Date = Date()) -> [PendingPlayback] {
        document.playback
            .filter { $0.ownerKey == ownerKey && $0.nextAttemptAt <= now }
            .sorted { $0.queuedAt < $1.queuedAt }
            .prefix(limit)
            .map { $0 }
    }

    func dueEvents(ownerKey: String, limit: Int, now: Date = Date()) -> [PendingProductEvent] {
        document.events
            .filter { $0.ownerKey == ownerKey && $0.nextAttemptAt <= now }
            .sorted { $0.queuedAt < $1.queuedAt }
            .prefix(limit)
            .map { $0 }
    }

    func dueErrors(ownerKey: String, limit: Int, now: Date = Date()) -> [PendingPlaybackError] {
        document.errors.filter { $0.ownerKey == ownerKey && $0.nextAttemptAt <= now }
            .sorted { $0.queuedAt < $1.queuedAt }.prefix(limit).map { $0 }
    }

    func removePlayback(sessionIds: Set<String>, ownerKey: String) throws {
        document.playback.removeAll {
            $0.ownerKey == ownerKey && sessionIds.contains($0.summary.sessionId)
        }
        try persist()
    }

    func removeEvents(eventIds: Set<String>, ownerKey: String) throws {
        document.events.removeAll {
            $0.ownerKey == ownerKey && eventIds.contains($0.event.eventId)
        }
        try persist()
    }

    func removeErrors(eventIds: Set<String>, ownerKey: String) throws {
        document.errors.removeAll { $0.ownerKey == ownerKey && eventIds.contains($0.event.eventId) }
        try persist()
    }

    func deferPlayback(sessionIds: Set<String>, ownerKey: String, now: Date = Date()) throws {
        for index in document.playback.indices where
            document.playback[index].ownerKey == ownerKey &&
            sessionIds.contains(document.playback[index].summary.sessionId) {
            document.playback[index].attemptCount += 1
            document.playback[index].nextAttemptAt = nextAttempt(
                count: document.playback[index].attemptCount,
                now: now
            )
        }
        try persist()
    }

    func deferEvents(eventIds: Set<String>, ownerKey: String, now: Date = Date()) throws {
        for index in document.events.indices where
            document.events[index].ownerKey == ownerKey &&
            eventIds.contains(document.events[index].event.eventId) {
            document.events[index].attemptCount += 1
            document.events[index].nextAttemptAt = nextAttempt(
                count: document.events[index].attemptCount,
                now: now
            )
        }
        try persist()
    }

    func deferErrors(eventIds: Set<String>, ownerKey: String, now: Date = Date()) throws {
        for index in document.errors.indices where document.errors[index].ownerKey == ownerKey && eventIds.contains(document.errors[index].event.eventId) {
            document.errors[index].attemptCount += 1
            document.errors[index].nextAttemptAt = nextAttempt(count: document.errors[index].attemptCount, now: now)
        }
        try persist()
    }

    func clear(ownerKey: String) throws {
        document.playback.removeAll { $0.ownerKey == ownerKey }
        document.events.removeAll { $0.ownerKey == ownerKey }
        document.errors.removeAll { $0.ownerKey == ownerKey }
        try persist()
    }

    func counts(ownerKey: String) -> (playback: Int, events: Int) {
        (
            document.playback.filter { $0.ownerKey == ownerKey }.count,
            document.events.filter { $0.ownerKey == ownerKey }.count
        )
    }

    private func nextAttempt(count: Int, now: Date) -> Date {
        let delays: [TimeInterval] = [30, 120, 600, 3_600, 21_600]
        let delay = delays[min(max(0, count - 1), delays.count - 1)]
        let jitter = Double.random(in: 0...(delay * 0.2))
        return now.addingTimeInterval(delay + jitter)
    }

    private func prune(now: Date) {
        let cutoff = now.addingTimeInterval(-retention)
        document.playback.removeAll { $0.queuedAt < cutoff }
        document.events.removeAll { $0.queuedAt < cutoff }
        document.errors.removeAll { $0.queuedAt < cutoff }
    }

    private func persist() throws {
        let directory = fileURL.deletingLastPathComponent()
        try FileManager.default.createDirectory(
            at: directory,
            withIntermediateDirectories: true
        )
        let data = try AnalyticsCoding.encoder().encode(document)
#if os(iOS) || os(tvOS) || os(watchOS)
        try data.write(to: fileURL, options: [.atomic, .completeFileProtectionUntilFirstUserAuthentication])
#else
        try data.write(to: fileURL, options: .atomic)
#endif
    }

    private static func load(from fileURL: URL) throws -> QueueDocument {
        let data = try Data(contentsOf: fileURL)
        return try AnalyticsCoding.decoder().decode(QueueDocument.self, from: data)
    }
}
