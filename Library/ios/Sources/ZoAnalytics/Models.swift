import Foundation

public enum AnalyticsPlatform: String, Codable, Sendable, Hashable {
    case ios
    case tvos
    case android
    case tv
}

public enum PlaybackContentType: String, Codable, Sendable {
    case movie
    case episode
    case live
}

public enum PlaybackUploadState: String, Codable, Sendable {
    case checkpoint
    case final
}

public enum PlaybackEndReason: String, Codable, Sendable {
    case completed
    case userClosed = "user_closed"
    case backPressed = "back_pressed"
    case contentChanged = "content_changed"
    case nextEpisode = "next_episode"
    case appBackgrounded = "app_backgrounded"
    case appTerminated = "app_terminated"
    case playbackError = "playback_error"
    case networkLost = "network_lost"
    case subscriptionExpired = "subscription_expired"
    case playerDestroyed = "player_destroyed"
    case unknown
}

public enum NetworkType: String, Codable, Sendable, Hashable {
    case wifi
    case cellular
    case ethernet
    case offline
    case unknown

    /// Most recently detected network type. Start analytics first so path monitoring is active.
    public static var detected: NetworkType {
        NetworkTypeMonitor.shared.current
    }
}

public struct AnalyticsCredentials: Sendable, Equatable {
    public let accessToken: String
    public let deviceToken: String
    public let ownerKey: String

    public init(accessToken: String, deviceToken: String, ownerKey: String) {
        self.accessToken = accessToken
        self.deviceToken = deviceToken
        self.ownerKey = ownerKey
    }
}

public struct AnalyticsRemoteConfiguration: Codable, Sendable, Equatable {
    public let enabled: Bool
    public let schemaVersion: Int
    public let minimumSdkVersion: String
    public let checkpointUploadEnabled: Bool
    public let localSnapshotIntervalSeconds: Int
    public let maxPendingSessions: Int
    public let pendingRetentionDays: Int
    public let maxBatchSize: Int
    public let maxPayloadBytes: Int
    public let sampleRate: Double
    public let presenceHeartbeatEnabled: Bool?
    public let presenceHeartbeatIntervalSeconds: Int?
    public let presenceTtlSeconds: Int?

    enum CodingKeys: String, CodingKey {
        case enabled
        case schemaVersion = "schema_version"
        case minimumSdkVersion = "minimum_sdk_version"
        case checkpointUploadEnabled = "checkpoint_upload_enabled"
        case localSnapshotIntervalSeconds = "local_snapshot_interval_seconds"
        case maxPendingSessions = "max_pending_sessions"
        case pendingRetentionDays = "pending_retention_days"
        case maxBatchSize = "max_batch_size"
        case maxPayloadBytes = "max_payload_bytes"
        case sampleRate = "sample_rate"
        case presenceHeartbeatEnabled = "presence_heartbeat_enabled"
        case presenceHeartbeatIntervalSeconds = "presence_heartbeat_interval_seconds"
        case presenceTtlSeconds = "presence_ttl_seconds"
    }
}

public enum AnalyticsPresenceState: String, Codable, Sendable {
    case foreground
    case background
}

public struct PlaybackContent: Codable, Sendable, Equatable {
    public var id: String
    public var type: PlaybackContentType
    public var seriesId: String?
    public var seasonId: String?
    public var episodeId: String?
    public var isDownloaded: Bool
    public var autoplay: Bool

    public init(
        id: String,
        type: PlaybackContentType,
        seriesId: String? = nil,
        seasonId: String? = nil,
        episodeId: String? = nil,
        isDownloaded: Bool = false,
        autoplay: Bool = false
    ) {
        self.id = id
        self.type = type
        self.seriesId = seriesId
        self.seasonId = seasonId
        self.episodeId = episodeId
        self.isDownloaded = isDownloaded
        self.autoplay = autoplay
    }

    enum CodingKeys: String, CodingKey {
        case id, type, autoplay
        case seriesId = "series_id"
        case seasonId = "season_id"
        case episodeId = "episode_id"
        case isDownloaded = "is_downloaded"
    }
}

public struct PlaybackTiming: Codable, Sendable, Equatable {
    public var durationMs: Int64
    public var watchPositionMs: Int64
    public var maxPositionMs: Int64
    public var watchedMs: Int64
    public var uniqueWatchedMs: Int64
    public var replayedMs: Int64
    public var foregroundWatchMs: Int64
    public var backgroundPlayMs: Int64
    public var startupMs: Int64?

    enum CodingKeys: String, CodingKey {
        case durationMs = "duration_ms"
        case watchPositionMs = "watch_position_ms"
        case maxPositionMs = "max_position_ms"
        case watchedMs = "watched_ms"
        case uniqueWatchedMs = "unique_watched_ms"
        case replayedMs = "replayed_ms"
        case foregroundWatchMs = "foreground_watch_ms"
        case backgroundPlayMs = "background_play_ms"
        case startupMs = "startup_ms"
    }
}

public struct PlaybackInteraction: Codable, Sendable, Equatable {
    public var playCount: Int
    public var pauseCount: Int
    public var resumeCount: Int
    public var seekCount: Int
    public var seekForwardMs: Int64
    public var seekBackwardMs: Int64
    public var fullscreenCount: Int
    public var pipCount: Int
    public var castCount: Int

    enum CodingKeys: String, CodingKey {
        case playCount = "play_count"
        case pauseCount = "pause_count"
        case resumeCount = "resume_count"
        case seekCount = "seek_count"
        case seekForwardMs = "seek_forward_ms"
        case seekBackwardMs = "seek_backward_ms"
        case fullscreenCount = "fullscreen_count"
        case pipCount = "pip_count"
        case castCount = "cast_count"
    }
}

public struct PlaybackBuffering: Codable, Sendable, Equatable {
    public var count: Int
    public var totalMs: Int64
    public var longestMs: Int64

    enum CodingKeys: String, CodingKey {
        case count
        case totalMs = "total_ms"
        case longestMs = "longest_ms"
    }
}

public struct PlaybackQuality: Codable, Sendable, Equatable {
    public var initial: String?
    public var final: String?
    public var changeCount: Int
    public var averageBitrateKbps: Int?
    public var bytesTransferred: Int64?
    public var droppedFrames: Int?
    public var renderedFrames: Int?
    public var videoCodec: String?
    public var audioCodec: String?
    public var streamFormat: String?

    public init(
        initial: String? = nil,
        final: String? = nil,
        changeCount: Int = 0,
        averageBitrateKbps: Int? = nil,
        droppedFrames: Int? = nil,
        renderedFrames: Int? = nil,
        videoCodec: String? = nil,
        audioCodec: String? = nil,
        streamFormat: String? = nil,
        bytesTransferred: Int64? = nil
    ) {
        self.initial = initial
        self.final = final
        self.changeCount = changeCount
        self.averageBitrateKbps = averageBitrateKbps
        self.bytesTransferred = bytesTransferred
        self.droppedFrames = droppedFrames
        self.renderedFrames = renderedFrames
        self.videoCodec = videoCodec
        self.audioCodec = audioCodec
        self.streamFormat = streamFormat
    }

    enum CodingKeys: String, CodingKey {
        case initial, final
        case changeCount = "change_count"
        case averageBitrateKbps = "average_bitrate_kbps"
        case bytesTransferred = "bytes_transferred"
        case droppedFrames = "dropped_frames"
        case renderedFrames = "rendered_frames"
        case videoCodec = "video_codec"
        case audioCodec = "audio_codec"
        case streamFormat = "stream_format"
    }
}

public struct PlaybackTracks: Codable, Sendable, Equatable {
    public var audioLanguage: String?
    public var subtitleEnabled: Bool
    public var subtitleLanguage: String?
    public var playbackSpeed: Double

    public init(
        audioLanguage: String? = nil,
        subtitleEnabled: Bool = false,
        subtitleLanguage: String? = nil,
        playbackSpeed: Double = 1
    ) {
        self.audioLanguage = audioLanguage
        self.subtitleEnabled = subtitleEnabled
        self.subtitleLanguage = subtitleLanguage
        self.playbackSpeed = playbackSpeed
    }

    enum CodingKeys: String, CodingKey {
        case audioLanguage = "audio_language"
        case subtitleEnabled = "subtitle_enabled"
        case subtitleLanguage = "subtitle_language"
        case playbackSpeed = "playback_speed"
    }
}

public struct PlaybackResult: Codable, Sendable, Equatable {
    public var completed: Bool
    public var completionPercent: Double
    public var milestones: [Int]
    public var errorCount: Int

    enum CodingKeys: String, CodingKey {
        case completed, milestones
        case completionPercent = "completion_percent"
        case errorCount = "error_count"
    }
}

public struct AnalyticsContext: Codable, Sendable, Equatable, Hashable {
    public var platform: AnalyticsPlatform
    public var networkType: NetworkType
    public var appVersion: String
    public var buildNumber: String
    public var osVersion: String
    public var deviceModel: String
    public var deviceCategory: String
    public var locale: String
    public var timezone: String

    public init(
        platform: AnalyticsPlatform = .ios,
        networkType: NetworkType = .unknown,
        appVersion: String,
        buildNumber: String,
        osVersion: String = ProcessInfo.processInfo.operatingSystemVersionString,
        deviceModel: String = "Apple",
        deviceCategory: String = "phone",
        locale: String = Locale.current.identifier,
        timezone: String = TimeZone.current.identifier
    ) {
        self.platform = platform
        self.networkType = networkType
        self.appVersion = appVersion
        self.buildNumber = buildNumber
        self.osVersion = osVersion
        self.deviceModel = deviceModel
        self.deviceCategory = deviceCategory
        self.locale = locale
        self.timezone = timezone
    }

    func fillingDetectedNetworkType() -> AnalyticsContext {
        guard networkType == .unknown, NetworkType.detected != .unknown else { return self }
        var copy = self
        copy.networkType = .detected
        return copy
    }

    enum CodingKeys: String, CodingKey {
        case platform, locale, timezone
        case networkType = "network_type"
        case appVersion = "app_version"
        case buildNumber = "build_number"
        case osVersion = "os_version"
        case deviceModel = "device_model"
        case deviceCategory = "device_category"
    }
}

public struct PlaybackPayload: Codable, Sendable, Equatable {
    public var schemaVersion: Int
    public var revision: Int
    public var state: PlaybackUploadState
    public var startedAt: Date
    public var endedAt: Date?
    public var endReason: PlaybackEndReason?
    public var content: PlaybackContent
    public var timing: PlaybackTiming
    public var interaction: PlaybackInteraction
    public var buffering: PlaybackBuffering
    public var quality: PlaybackQuality
    public var tracks: PlaybackTracks
    public var result: PlaybackResult
    public var context: AnalyticsContext

    enum CodingKeys: String, CodingKey {
        case content, timing, interaction, buffering, quality, tracks, result, context, state, revision
        case schemaVersion = "schema_version"
        case startedAt = "started_at"
        case endedAt = "ended_at"
        case endReason = "end_reason"
    }
}

public struct PlaybackSummary: Codable, Sendable, Equatable {
    public let sessionId: String
    public let payload: PlaybackPayload

    public init(sessionId: String, payload: PlaybackPayload) {
        self.sessionId = sessionId
        self.payload = payload
    }

    enum CodingKeys: String, CodingKey {
        case sessionId = "session_id"
        case payload
    }
}

public enum JSONValue: Codable, Sendable, Equatable {
    case string(String)
    case integer(Int64)
    case double(Double)
    case bool(Bool)
    case array([JSONValue])
    case object([String: JSONValue])
    case null

    public init(from decoder: Decoder) throws {
        let container = try decoder.singleValueContainer()
        if container.decodeNil() { self = .null }
        else if let value = try? container.decode(Bool.self) { self = .bool(value) }
        else if let value = try? container.decode(Int64.self) { self = .integer(value) }
        else if let value = try? container.decode(Double.self) { self = .double(value) }
        else if let value = try? container.decode(String.self) { self = .string(value) }
        else if let value = try? container.decode([JSONValue].self) { self = .array(value) }
        else { self = .object(try container.decode([String: JSONValue].self)) }
    }

    public func encode(to encoder: Encoder) throws {
        var container = encoder.singleValueContainer()
        switch self {
        case .string(let value): try container.encode(value)
        case .integer(let value): try container.encode(value)
        case .double(let value): try container.encode(value)
        case .bool(let value): try container.encode(value)
        case .array(let value): try container.encode(value)
        case .object(let value): try container.encode(value)
        case .null: try container.encodeNil()
        }
    }
}

public struct ProductEvent: Codable, Sendable, Equatable {
    public static let supportedNames: Set<String> = [
        "app_opened", "app_foregrounded", "app_backgrounded", "app_session_ended",
        "screen_viewed", "content_impression", "content_opened", "search_performed",
        "search_result_selected", "search_empty", "wishlist_added", "wishlist_removed",
        "download_started", "download_completed", "download_failed", "notification_opened",
        "paywall_viewed", "plan_selected", "purchase_started", "purchase_completed",
        "purchase_failed", "purchase_cancelled", "restore_purchase_completed"
    ]

    public let eventId: String
    public let name: String
    public let occurredAt: Date
    public let appSessionId: String
    public let properties: [String: JSONValue]

    public init(
        eventId: String = UUID().uuidString.lowercased(),
        name: String,
        occurredAt: Date = Date(),
        appSessionId: String,
        properties: [String: JSONValue] = [:]
    ) {
        precondition(Self.supportedNames.contains(name), "Unsupported analytics event: \(name)")
        self.eventId = eventId
        self.name = name
        self.occurredAt = occurredAt
        self.appSessionId = appSessionId
        self.properties = properties
    }

    enum CodingKeys: String, CodingKey {
        case name, properties
        case eventId = "event_id"
        case occurredAt = "occurred_at"
        case appSessionId = "app_session_id"
    }
}

public struct PlaybackErrorEvent: Codable, Sendable, Equatable {
    public let schemaVersion: Int
    public let eventId: String
    public let occurredAt: Date
    public let positionMs: Int64
    public let category: String
    public let stage: String
    public let code: String
    public let httpStatus: Int?
    public let isFatal: Bool
    public let isRetryable: Bool
    public let retryCount: Int
    public let networkType: NetworkType
    public let sanitizedMessage: String?

    public init(
        eventId: String = UUID().uuidString.lowercased(),
        occurredAt: Date = Date(),
        positionMs: Int64,
        category: String,
        stage: String,
        code: String,
        httpStatus: Int? = nil,
        isFatal: Bool,
        isRetryable: Bool,
        retryCount: Int = 0,
        networkType: NetworkType = .unknown,
        sanitizedMessage: String? = nil
    ) {
        self.schemaVersion = 1
        self.eventId = eventId
        self.occurredAt = occurredAt
        self.positionMs = max(0, positionMs)
        self.category = String(category.prefix(64))
        self.stage = String(stage.prefix(64))
        self.code = String(code.prefix(128))
        self.httpStatus = httpStatus
        self.isFatal = isFatal
        self.isRetryable = isRetryable
        self.retryCount = max(0, retryCount)
        self.networkType = networkType
        self.sanitizedMessage = sanitizedMessage.map { String($0.prefix(500)) }
    }

    enum CodingKeys: String, CodingKey {
        case category, stage, code
        case schemaVersion = "schema_version"
        case eventId = "event_id"
        case occurredAt = "occurred_at"
        case positionMs = "position_ms"
        case httpStatus = "http_status"
        case isFatal = "is_fatal"
        case isRetryable = "is_retryable"
        case retryCount = "retry_count"
        case networkType = "network_type"
        case sanitizedMessage = "sanitized_message"
    }
}
