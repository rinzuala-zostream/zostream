import Foundation

public struct ZoAnalyticsConfiguration: Sendable {
    public static let sdkVersion = "1.4.0"

    public var baseURL: URL
    public var localSnapshotInterval: TimeInterval
    public var maxPendingSessions: Int
    public var retentionDays: Int
    public var requestTimeout: TimeInterval
    public var automaticLifecycleTracking: Bool
    public var presenceHeartbeatInterval: TimeInterval

    public init(
        baseURL: URL = URL(string: "https://zostream.in/")!,
        localSnapshotInterval: TimeInterval = 30,
        maxPendingSessions: Int = 500,
        retentionDays: Int = 7,
        requestTimeout: TimeInterval = 15,
        automaticLifecycleTracking: Bool = true,
        presenceHeartbeatInterval: TimeInterval = 60
    ) {
        self.baseURL = baseURL
        self.localSnapshotInterval = max(15, localSnapshotInterval)
        self.maxPendingSessions = max(1, maxPendingSessions)
        self.retentionDays = max(1, retentionDays)
        self.requestTimeout = max(5, requestTimeout)
        self.automaticLifecycleTracking = automaticLifecycleTracking
        self.presenceHeartbeatInterval = max(30, presenceHeartbeatInterval)
    }

    func endpoint(_ relativePath: String) -> URL {
        URL(string: relativePath, relativeTo: baseURL)!.absoluteURL
    }
}

enum AnalyticsCoding {
    static func encoder() -> JSONEncoder {
        let encoder = JSONEncoder()
        encoder.dateEncodingStrategy = .iso8601
        encoder.outputFormatting = [.sortedKeys]
        return encoder
    }

    static func decoder() -> JSONDecoder {
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601
        return decoder
    }
}
