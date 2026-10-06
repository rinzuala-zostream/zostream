import Foundation
#if canImport(FoundationNetworking)
import FoundationNetworking
#endif

public enum ZoAnalyticsError: Error, Sendable, Equatable {
    case collectionDisabled
    case invalidResponse
    case http(status: Int, retryable: Bool)
    case payloadTooLarge
}

public protocol AnalyticsTransport: Sendable {
    func data(for request: URLRequest) async throws -> (Data, HTTPURLResponse)
}

public struct URLSessionAnalyticsTransport: AnalyticsTransport, @unchecked Sendable {
    private let session: URLSession

    public init(session: URLSession = .shared) {
        self.session = session
    }

    public func data(for request: URLRequest) async throws -> (Data, HTTPURLResponse) {
        let (data, response) = try await session.data(for: request)
        guard let response = response as? HTTPURLResponse else {
            throw ZoAnalyticsError.invalidResponse
        }
        return (data, response)
    }
}

private struct PlaybackBatchItem: Encodable {
    let sessionId: String
    let summary: PlaybackPayload

    enum CodingKeys: String, CodingKey {
        case summary
        case sessionId = "session_id"
    }
}

private struct PlaybackBatchBody: Encodable {
    let schemaVersion = 1
    let sessions: [PlaybackBatchItem]

    enum CodingKeys: String, CodingKey {
        case sessions
        case schemaVersion = "schema_version"
    }
}

private struct EventBatchBody: Encodable {
    let schemaVersion = 1
    let events: [ProductEvent]
    let context: AnalyticsContext

    enum CodingKeys: String, CodingKey {
        case events, context
        case schemaVersion = "schema_version"
    }
}

private struct APIDataEnvelope<Value: Decodable>: Decodable {
    let data: Value
}

public actor ZoAnalyticsClient {
    public let configuration: ZoAnalyticsConfiguration
    private let queue: AnalyticsQueue
    private let transport: any AnalyticsTransport
    private var collectionEnabled: Bool

    public init(
        configuration: ZoAnalyticsConfiguration = .init(),
        queueDirectory: URL? = nil,
        transport: any AnalyticsTransport = URLSessionAnalyticsTransport(),
        collectionEnabled: Bool = true
    ) {
        self.configuration = configuration
        self.transport = transport
        self.collectionEnabled = collectionEnabled
        let directory = queueDirectory ?? Self.defaultQueueDirectory()
        self.queue = AnalyticsQueue(
            fileURL: directory.appendingPathComponent("queue-v1.json"),
            maxPendingSessions: configuration.maxPendingSessions,
            retentionDays: configuration.retentionDays
        )
    }

    public func submit(
        _ summary: PlaybackSummary,
        credentials: AnalyticsCredentials
    ) async {
        guard collectionEnabled else { return }
        do {
            try await queue.upsert(summary, ownerKey: credentials.ownerKey)
            try await flush(credentials: credentials)
        } catch {
            // A persisted item remains available for the next foreground flush.
        }
    }

    public func saveLocally(_ summary: PlaybackSummary, ownerKey: String) async throws {
        guard collectionEnabled else { return }
        try await queue.upsert(summary, ownerKey: ownerKey)
    }

    public func track(
        _ event: ProductEvent,
        context: AnalyticsContext,
        credentials: AnalyticsCredentials,
        flushImmediately: Bool = false
    ) async {
        guard collectionEnabled else { return }
        do {
            try await queue.enqueue(event, context: context, ownerKey: credentials.ownerKey)
            if flushImmediately { try await flushEvents(credentials: credentials) }
        } catch {
            // Product analytics never interrupts the host app.
        }
    }

    public func submitPlaybackError(
        _ error: PlaybackErrorEvent,
        sessionId: String,
        platform: AnalyticsPlatform,
        credentials: AnalyticsCredentials
    ) async throws {
        guard collectionEnabled else { return }
        try await send(
            method: "POST",
            path: "api/v4/analytic/playback/\(sessionId)/errors",
            body: error,
            credentials: credentials,
            platform: platform.rawValue
        )
    }

    public func flush(credentials: AnalyticsCredentials) async throws {
        guard collectionEnabled else { return }
        let pending = await queue.duePlayback(ownerKey: credentials.ownerKey, limit: 20)
        guard !pending.isEmpty else {
            try await flushEvents(credentials: credentials)
            return
        }

        let sessionIds = Set(pending.map { $0.summary.sessionId })
        do {
            if pending.count == 1, let item = pending.first {
                try await sendPlayback(item.summary, credentials: credentials)
            } else {
                try await sendPlaybackBatch(pending.map(\.summary), credentials: credentials)
            }
            try await queue.removePlayback(sessionIds: sessionIds, ownerKey: credentials.ownerKey)
        } catch let error as ZoAnalyticsError {
            if case .http(let status, let retryable) = error, !retryable, status != 401 {
                try await queue.removePlayback(sessionIds: sessionIds, ownerKey: credentials.ownerKey)
            } else {
                try await queue.deferPlayback(sessionIds: sessionIds, ownerKey: credentials.ownerKey)
            }
            throw error
        } catch {
            try await queue.deferPlayback(sessionIds: sessionIds, ownerKey: credentials.ownerKey)
            throw error
        }

        try await flushEvents(credentials: credentials)
    }

    public func clearPending(ownerKey: String) async throws {
        try await queue.clear(ownerKey: ownerKey)
    }

    public func fetchRemoteConfiguration(
        credentials: AnalyticsCredentials,
        platform: AnalyticsPlatform
    ) async throws -> AnalyticsRemoteConfiguration {
        guard collectionEnabled else { throw ZoAnalyticsError.collectionDisabled }
        var request = URLRequest(url: configuration.endpoint("api/v4/analytic/config"))
        request.httpMethod = "GET"
        request.timeoutInterval = configuration.requestTimeout
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("Bearer \(credentials.accessToken)", forHTTPHeaderField: "Authorization")
        request.setValue(credentials.deviceToken, forHTTPHeaderField: "Device-Token")
        request.setValue(ZoAnalyticsConfiguration.sdkVersion, forHTTPHeaderField: "X-Analytics-SDK-Version")
        request.setValue(platform.rawValue, forHTTPHeaderField: "X-Platform")
        let (data, response) = try await transport.data(for: request)
        guard (200...299).contains(response.statusCode) else {
            let retryable = response.statusCode == 404
                || response.statusCode == 408
                || response.statusCode == 429
                || response.statusCode >= 500
            throw ZoAnalyticsError.http(status: response.statusCode, retryable: retryable)
        }
        return try AnalyticsCoding.decoder()
            .decode(APIDataEnvelope<AnalyticsRemoteConfiguration>.self, from: data)
            .data
    }

    public func pendingCounts(ownerKey: String) async -> (playback: Int, events: Int) {
        await queue.counts(ownerKey: ownerKey)
    }

    /// Disabling collection stops new records and network requests. Existing
    /// queued records remain on disk for a later enabled session.
    public func setCollectionEnabled(_ enabled: Bool) {
        collectionEnabled = enabled
    }

    public func isCollectionEnabled() -> Bool {
        collectionEnabled
    }

    private func flushEvents(credentials: AnalyticsCredentials) async throws {
        let pending = await queue.dueEvents(ownerKey: credentials.ownerKey, limit: 50)
        guard !pending.isEmpty else { return }
        let ids = Set(pending.map { $0.event.eventId })
        do {
            let groups = Dictionary(grouping: pending, by: \.context)
            for (context, items) in groups {
                let body = EventBatchBody(events: items.map(\.event), context: context)
                try await send(
                    method: "POST",
                    path: "api/v4/analytic/events/batch",
                    body: body,
                    credentials: credentials,
                    platform: context.platform.rawValue
                )
            }
            try await queue.removeEvents(eventIds: ids, ownerKey: credentials.ownerKey)
        } catch let error as ZoAnalyticsError {
            if case .http(let status, let retryable) = error, !retryable, status != 401 {
                try await queue.removeEvents(eventIds: ids, ownerKey: credentials.ownerKey)
            } else {
                try await queue.deferEvents(eventIds: ids, ownerKey: credentials.ownerKey)
            }
            throw error
        } catch {
            try await queue.deferEvents(eventIds: ids, ownerKey: credentials.ownerKey)
            throw error
        }
    }

    private func sendPlayback(
        _ summary: PlaybackSummary,
        credentials: AnalyticsCredentials
    ) async throws {
        try await send(
            method: "PUT",
            path: "api/v4/analytic/playback/\(summary.sessionId)",
            body: summary.payload,
            credentials: credentials,
            platform: summary.payload.context.platform.rawValue
        )
    }

    private func sendPlaybackBatch(
        _ summaries: [PlaybackSummary],
        credentials: AnalyticsCredentials
    ) async throws {
        let items = summaries.map {
            PlaybackBatchItem(
                sessionId: $0.sessionId,
                summary: $0.payload
            )
        }
        try await send(
            method: "POST",
            path: "api/v4/analytic/playback/batch",
            body: PlaybackBatchBody(sessions: items),
            credentials: credentials,
            platform: summaries.first?.payload.context.platform.rawValue ?? "ios"
        )
    }

    private func send<Body: Encodable>(
        method: String,
        path: String,
        body: Body,
        credentials: AnalyticsCredentials,
        platform: String
    ) async throws {
        var request = URLRequest(url: configuration.endpoint(path))
        request.httpMethod = method
        request.timeoutInterval = configuration.requestTimeout
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue("Bearer \(credentials.accessToken)", forHTTPHeaderField: "Authorization")
        request.setValue(credentials.deviceToken, forHTTPHeaderField: "Device-Token")
        request.setValue(ZoAnalyticsConfiguration.sdkVersion, forHTTPHeaderField: "X-Analytics-SDK-Version")
        request.setValue(platform, forHTTPHeaderField: "X-Platform")
        request.httpBody = try AnalyticsCoding.encoder().encode(body)
        let payloadLimit = path.hasSuffix("/batch") ? 262_144 : 65_536
        if (request.httpBody?.count ?? 0) > payloadLimit { throw ZoAnalyticsError.payloadTooLarge }

        let (_, response) = try await transport.data(for: request)
        guard (200...299).contains(response.statusCode) else {
            let retryable = response.statusCode == 404
                || response.statusCode == 408
                || response.statusCode == 429
                || response.statusCode >= 500
            throw ZoAnalyticsError.http(status: response.statusCode, retryable: retryable)
        }
    }

    private static func defaultQueueDirectory() -> URL {
        let root = FileManager.default.urls(
            for: .applicationSupportDirectory,
            in: .userDomainMask
        ).first ?? FileManager.default.temporaryDirectory
        return root.appendingPathComponent("ZoAnalytics", isDirectory: true)
    }
}
