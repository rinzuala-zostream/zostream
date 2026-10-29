import Foundation

public struct ChatWatchInvite: Decodable, Sendable {
    public let session_id: String
    public let content_type: String
    public let content_id: String
    public let content_title: String
    public let active: Bool?
}

public struct ChatRoomInvitation: Decodable, Sendable, Equatable {
    public let room_id: String
    public let room_name: String
    public let active: Bool
}

public struct ChatMessage: Decodable, Identifiable, Sendable {
    public let id: String
    public let room_id: String
    public let user_id: String
    public let request_id: String
    public let display_name: String
    public let body: String?
    public let spoiler: Bool
    public let deleted: Bool
    public let created_at: String
    public let room_invite: ChatRoomInvitation?
    public let watch_invite: ChatWatchInvite?
}
public struct ChatPage: Decodable, Sendable {
    public let messages: [ChatMessage]
    public let has_more: Bool
    public init(messages: [ChatMessage], has_more: Bool) { self.messages = messages; self.has_more = has_more }
}
public struct ChatRoom: Decodable, Sendable {
    public let room_id: String
    public let room_type: String
    public let channel: String
    public let websocket_url: String
    public let user_id: String
    public let is_host: Bool?
    public let room_name: String?
    public let content_type: String?
    public let watch_session: ChatWatchInvite?
}
public struct ChatFailure: LocalizedError {
    public let message: String
    public let statusCode: Int?
    public init(message: String, statusCode: Int? = nil) { self.message = message; self.statusCode = statusCode }
    public var errorDescription: String? { message }
}

/// Main-actor lifecycle; no credentials or messages are persisted by this client.
@MainActor
public final class ZoChatClient {
    private let baseURL: URL
    private let credentials: () -> AnalyticsCredentials?
    private let playbackToken: () -> String?
    private let refreshCredentials: ((String) async throws -> Void)?
    private let version: String
    private let session: URLSession
    private let socketPoolNamespace: String
    private var socket: ChatSocketLease?
    private var receiveTask: Task<Void, Never>?
    private var retryTask: Task<Void, Never>?
    private var syncTask: Task<Void, Never>?
    private var recoveryTask: Task<Void, Never>?
    private var room: ChatRoom?
    private var roomRevision = 0
    private var socketId: String?
    private var socketURL: String?
    private var channel: String?
    private var subscriptionTask: Task<Void, Never>?
    private var closed = false
    private var generation = 0
    private var owner: String?
    private var retry = 0
    private var lastFallbackSync: TimeInterval = 0
    private var lastTranscriptReconcile = ProcessInfo.processInfo.systemUptime
    private var hasSubscribedRoom = false
    public private(set) var isConnected = false
    public var onBadgeEvent: (([String: Any]) -> Void)?
    public var onMessage: ((ChatMessage) -> Void)?
    private var controlToken: String?
    private var controlChannel: String?
    private var controlSubscribed = false
    private var feedChannel: String?
    private var feedSubscribed = false
    private var visibilityAcknowledged: Int64 = 0
    private var recoveryDelay: Double = 5
    private var liveVersions: [String: UInt64] = [:]
    private var liveKinds: [String: String] = [:]
    private var pendingLive: [String: String] = [:]
    private var liveRecoveryTask: Task<Void, Never>?
    private var liveRecoveryDelay: Double = 2.5
    private var unreadChannels: Set<String>?
    private var batchAuthorization = false
    private var unreadSubscribed: Set<String> = []
    private var unreadPhysical: [String: String] = [:]
    private var unreadExpiry: [String: TimeInterval] = [:]
    private var subscriptionExpiry: TimeInterval = 0

    private func matchesChannel(_ actual: String, _ logical: String) -> Bool {
        let prefix = logical + ".lease."
        return actual == logical || (actual.hasPrefix(prefix) && UInt64(actual.dropFirst(prefix.count)) != nil)
    }

    private func revokeRoom() async {
        let revision = roomRevision
        if let channel { try? await sendEvent("pusher:unsubscribe", data: ["channel": channel]) }
        guard !closed, roomRevision == revision else { return }
        roomRevision += 1; room = nil; channel = nil; isConnected = false
        onConnection?(false); onChanged?("access_denied", nil)
    }
    public var onRoomInviteState: (([String: Any]) -> Void)?
    public var onWatchState: (([String: Any]) -> Void)?
    public var onChanged: ((String, String?) -> Void)?
    public var onConnection: ((Bool) -> Void)?

    public init(baseURL: URL, version: String, credentials: @escaping () -> AnalyticsCredentials?, playbackToken: @escaping () -> String? = { nil }, refreshCredentials: ((String) async throws -> Void)? = nil, session: URLSession? = nil) {
        self.refreshCredentials = refreshCredentials
        self.baseURL = baseURL; self.version = version; self.credentials = credentials; self.playbackToken = playbackToken
        let config = URLSessionConfiguration.ephemeral
        config.timeoutIntervalForRequest = 15
        config.timeoutIntervalForResource = 20
        self.session = session ?? URLSession(configuration: config)
        socketPoolNamespace = session.map { "injected-\(ObjectIdentifier($0))" } ?? "default"
    }

    public func request<T: Decodable>(_ path: String, body: [String: Any]? = nil, as type: T.Type, retryUnauthorized: Bool = true) async throws -> T {
        guard baseURL.scheme == "https" else { throw ChatFailure(message: "Chat API must use HTTPS") }
        guard !closed, let c = credentials(), owner == nil || owner == c.ownerKey else { throw ChatFailure(message: "Please sign in again and reopen chat.") }
        guard let url = URL(string: baseURL.absoluteString.trimmingCharacters(in: CharacterSet(charactersIn: "/")) + "/api/v4/chat/" + path) else { throw ChatFailure(message: "Invalid chat URL") }
        var request = URLRequest(url: url)
        request.setValue("Bearer " + c.accessToken.replacingOccurrences(of: "Bearer ", with: ""), forHTTPHeaderField: "Authorization")
        request.setValue(c.deviceToken, forHTTPHeaderField: "Device-Token")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue(ZoAnalyticsConfiguration.sdkVersion, forHTTPHeaderField: "X-Analytics-SDK-Version")
        request.setValue("ios", forHTTPHeaderField: "X-Client-Platform")
        request.setValue(version, forHTTPHeaderField: "X-Client-Version")
        request.setValue(playbackToken(), forHTTPHeaderField: "X-Playback-Token")
        if let body {
            request.httpMethod = "POST"
            request.setValue("application/json", forHTTPHeaderField: "Content-Type")
            request.httpBody = try JSONSerialization.data(withJSONObject: body)
        }
        let (data, response) = try await session.data(for: request)
        guard !closed, credentials()?.ownerKey == c.ownerKey else { throw CancellationError() }
        if (response as? HTTPURLResponse)?.statusCode == 401, retryUnauthorized, let refreshCredentials {
            try await refreshCredentials(c.accessToken)
            return try await self.request(path, body: body, as: type, retryUnauthorized: false)
        }
        guard let json = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any] else {
            throw ChatFailure(message: "Chat returned an unreadable response. Please try again.")
        }
        let successValue = json["success"]
        let declaredSuccess: Bool
        if let number = successValue as? NSNumber { declaredSuccess = number.intValue != 0 }
        else if let string = successValue as? String { declaredSuccess = string.lowercased() == "true" || string == "1" }
        else { declaredSuccess = (json["status"] as? String)?.lowercased() == "success" }
        let payloadKeys = ["room_id", "user_id", "messages", "invites", "rooms", "ended", "accepted", "position_ms",
                           "online_count", "auth", "blocked", "left", "users", "reported", "resolved", "updated"]
        let undeclared = successValue == nil || successValue is NSNull
        let directPayload = payloadKeys.contains { json[$0] != nil && !(json[$0] is NSNull) }
        let successfulEnvelope = declaredSuccess || (undeclared && (json["data"] is [String: Any] || directPayload))
        guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode), successfulEnvelope else {
            let message = (json["message"] as? String).flatMap { $0.isEmpty ? nil : $0 }
                ?? ((json["error"] as? [String: Any])?["message"] as? String)
            throw ChatFailure(message: message ?? "Chat request failed", statusCode: (response as? HTTPURLResponse)?.statusCode)
        }
        let payload = json["data"] as? [String: Any] ?? json["result"] as? [String: Any] ?? json
        return try JSONDecoder().decode(T.self, from: JSONSerialization.data(withJSONObject: payload))
    }

    private var roomVisible: Bool?
    private var visibilitySequence: Int64 = 0
    private let visibilityID = UUID().uuidString
    private func visibilityBody(_ visible: Bool) -> [String: Any] {
        visibilitySequence = max(visibilitySequence + 1, Int64(Date().timeIntervalSince1970 * 1000))
        return ["visible": visible, "visibility_sequence": visibilitySequence, "visibility_id": visibilityID]
    }

    // This final visibility request must survive closing the chat transport.
    private func reportVisibility(_ id: String, visible: Bool) {
        let body = visibilityBody(visible)
        if isConnected, controlSubscribed, controlToken != nil, let channel = controlChannel, let socket {
            let frame: [String: Any] = ["event": "chat:presence", "channel": channel, "data": body]
            let sequence = body["visibility_sequence"] as? Int64 ?? 0
            Task { [weak self] in
                do {
                    let raw = try JSONSerialization.data(withJSONObject: frame)
                    try await socket.send(.string(String(decoding: raw, as: UTF8.self)))
                    try await Task.sleep(nanoseconds: 2_500_000_000)
                    if let self, self.visibilityAcknowledged < sequence { self.reportVisibilityHTTP(id, body: body) }
                } catch { self?.reportVisibilityHTTP(id, body: body) }
            }
        } else { reportVisibilityHTTP(id, body: body) }
    }
    private func reportVisibilityHTTP(_ id: String, body: [String: Any]) {
        guard let c = credentials(), owner == nil || owner == c.ownerKey,
              let url = URL(string: baseURL.absoluteString.trimmingCharacters(in: CharacterSet(charactersIn: "/")) + "/api/v4/chat/rooms/\(id)/heartbeat") else { return }
        var request = URLRequest(url: url, timeoutInterval: 5)
        request.httpMethod = "POST"
        request.setValue("Bearer " + c.accessToken.replacingOccurrences(of: "Bearer ", with: ""), forHTTPHeaderField: "Authorization")
        request.setValue(c.deviceToken, forHTTPHeaderField: "Device-Token")
        request.setValue("ios", forHTTPHeaderField: "X-Client-Platform")
        request.setValue(version, forHTTPHeaderField: "X-Client-Version")
        request.setValue(playbackToken(), forHTTPHeaderField: "X-Playback-Token")
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.httpBody = try? JSONSerialization.data(withJSONObject: body)
        URLSession.shared.dataTask(with: request).resume()
    }

    public func setRoomVisible(_ visible: Bool) {
        roomVisible = visible
        if !visible { clearFeed() }
        else if onMessage != nil, feedChannel == nil, isConnected { subscribe() }
        if let room, unreadChannels == nil { reportVisibility(room.room_id, visible: visible) }
    }

    public func join(type: String, contentId: String?) async throws -> ChatRoom {
        let identity = credentials()?.ownerKey
        guard !closed, owner == nil || owner == identity else {
            throw ChatFailure(message: "Account changed. Reopen chat.")
        }
        owner = identity
        if let room, roomVisible != nil { reportVisibility(room.room_id, visible: false) }
        roomRevision += 1; let revision = roomRevision
        clearFeed(); controlToken = nil; controlChannel = nil; liveVersions.removeAll(); liveKinds.removeAll(); pendingLive.removeAll()
        subscriptionTask?.cancel()
        let previousChannel = channel
        isConnected = false
        channel = nil; room = nil; subscriptionExpiry = 0
        if let previousChannel {
            do { try await sendEvent("pusher:unsubscribe", data: ["channel": previousChannel]) }
            catch { if !Task.isCancelled, revision == roomRevision { reconnect(generation) } }
        }
        guard !closed, revision == roomRevision, !Task.isCancelled else { throw CancellationError() }
        let info: ChatRoom
        if type == "invite", let contentId {
            info = try await request("invite-rooms/\(contentId)/join", body: [:], as: ChatRoom.self)
        } else {
            var body: [String: Any] = ["type": type]
            if let contentId { body["content_id"] = contentId }
            info = try await request("rooms/join", body: body, as: ChatRoom.self)
        }
        guard !closed, revision == roomRevision, !Task.isCancelled else { throw CancellationError() }
        room = info
        if let roomVisible { reportVisibility(info.room_id, visible: roomVisible) }
        if socket != nil, socketURL == info.websocket_url { subscribe() }
        else { connect() }
        startReconciliation()
        return info
    }

    public func configureUnread(channels: [String], websocketURL: String, batchAuthorization: Bool = false) {
        self.batchAuthorization = batchAuthorization
        guard !closed, let identity = credentials()?.ownerKey, owner == nil || owner == identity else { return }
        owner = identity
        let wanted = Set(channels)
        if !wanted.subtracting(unreadChannels ?? []).isEmpty { isConnected = false }
        let removed = (unreadChannels ?? []).subtracting(wanted)
        unreadChannels = wanted; unreadSubscribed.formIntersection(wanted)
        room = ChatRoom(room_id: "", room_type: "unread", channel: "", websocket_url: websocketURL,
                        user_id: "", is_host: nil, room_name: nil, content_type: nil, watch_session: nil)
        let removedPhysical = removed.compactMap { unreadPhysical.removeValue(forKey: $0) }
        for target in removed { unreadExpiry.removeValue(forKey: target) }
        Task { for channel in removedPhysical { try? await sendEvent("pusher:unsubscribe", data: ["channel": channel]) } }
        if socket != nil && socketURL == websocketURL { subscribe() } else { connect() }
        startReconciliation()
    }

    private func startReconciliation() {
        syncTask?.cancel()
        recoveryTask?.cancel()
        if unreadChannels == nil {
            recoveryTask = Task { [weak self] in
                while !Task.isCancelled {
                    let delay = self?.recoveryDelay ?? 5
                    let jitter = delay > 5 ? Double.random(in: 0...(delay / 5)) : 0
                    do { try await Task.sleep(nanoseconds: UInt64((delay + jitter) * 1_000_000_000)) } catch { return }
                    guard let self, !self.closed else { return }
                    if self.credentials()?.ownerKey != self.owner { self.close(); return }
                    if self.room != nil && self.roomVisible != false && !self.isConnected {
                        self.onChanged?("poll", nil); self.recoveryDelay = min(60, self.recoveryDelay * 2)
                    } else { self.recoveryDelay = 5 }
                }
            }
        }
        syncTask = Task { [weak self] in
            while !Task.isCancelled {
                do { try await Task.sleep(nanoseconds: 30_000_000_000) } catch { return }
                guard let self, !self.closed else { return }
                if self.credentials()?.ownerKey != self.owner { self.close(); return }
                let socketGeneration = self.generation
                self.socket?.sendPing { [weak self] error in
                    if error != nil { Task { @MainActor in self?.reconnect(socketGeneration) } }
                }
                if let room = self.room, self.unreadChannels == nil, self.roomVisible != false {
                    struct Presence: Decodable { let online_count: Int }
                    let revision = self.roomRevision
                    do {
                        if self.controlToken != nil && self.isConnected { self.reportVisibility(room.room_id, visible: self.roomVisible ?? false) }
                        else {
                            let _: Presence = try await self.request("rooms/\(room.room_id)/heartbeat", body: self.roomVisible.map { self.visibilityBody($0) } ?? [:], as: Presence.self)
                        }
                    } catch let error as ChatFailure where [403, 404, 410].contains(error.statusCode ?? 0) {
                        if revision == self.roomRevision { await self.revokeRoom() }
                    } catch { /* Reconcile and retry transient failures. */ }
                }
                let now = ProcessInfo.processInfo.systemUptime
                if self.room != nil && (self.unreadChannels != nil
                    ? self.unreadExpiry.values.contains(where: { $0 <= now }) : self.subscriptionExpiry <= now) {
                    self.subscribe()
                }
                if self.room != nil && self.unreadChannels == nil && self.isConnected && now - self.lastTranscriptReconcile >= 300 {
                    self.lastTranscriptReconcile = now; self.onChanged?("reconcile", nil)
                }
                if self.unreadChannels != nil && !self.isConnected && now - self.lastFallbackSync >= 60 {
                    self.lastFallbackSync = now; self.onChanged?("sync", nil)
                }
            }
        }
    }

    private func sendEvent(_ event: String, data: [String: Any]) async throws {
        guard let socket else { return }
        let bytes = try JSONSerialization.data(withJSONObject: ["event": event, "data": data])
        try await socket.send(.string(String(decoding: bytes, as: UTF8.self)))
    }

    private func subscribe() {
        guard let socketId, let room else { return }
        let current = generation, revision = roomRevision
        subscriptionTask?.cancel()
        if let targets = unreadChannels {
            subscriptionTask = Task { [weak self] in
                guard let self else { return }
                do {
                    struct Auth: Decodable { let auth: String; let channel: String; let expires_in: Int?; let control_token: String?; let control_channel: String?; let control_auth: String?; let feed_channel: String?; let feed_auth: String? }
                    let due = targets.filter { !self.unreadSubscribed.contains($0) || (self.unreadExpiry[$0] ?? 0) <= ProcessInfo.processInfo.systemUptime }
                    struct Batch: Decodable { let authorizations: [String: Auth] }
                    var batch: [String: Auth] = [:]
                    if self.batchAuthorization && !due.isEmpty {
                        let requested = Array(due)
                        for start in stride(from: 0, to: requested.count, by: 64) {
                            let channels = Array(requested[start..<min(start + 64, requested.count)])
                            let result = try await self.request("unread/auth-batch", body: ["socket_id": socketId, "channels": channels], as: Batch.self)
                            batch.merge(result.authorizations) { _, new in new }
                        }
                    }
                    for target in due {
                        let auth: Auth
                        if let cached = batch[target] { auth = cached }
                        else { auth = try await self.request("unread/auth", body: ["socket_id": socketId, "channel": target], as: Auth.self) }
                        guard !self.closed, current == self.generation, self.unreadChannels?.contains(target) == true, !Task.isCancelled else { return }
                        guard self.matchesChannel(auth.channel, target) else { throw ChatFailure(message: "Badge channel mismatch") }
                        let old = self.unreadPhysical.updateValue(auth.channel, forKey: target)
                        self.unreadExpiry[target] = ProcessInfo.processInfo.systemUptime + Double(min(300, max(1, auth.expires_in ?? 300))) + ((self.batchAuthorization || auth.control_token != nil) ? Double.random(in: 0...5) : 0)
                        if old != auth.channel || !self.unreadSubscribed.contains(target) {
                            self.isConnected = false
                            self.unreadSubscribed.remove(target)
                            try await self.sendEvent("pusher:subscribe", data: ["channel": auth.channel, "auth": auth.auth])
                            if let old, old != auth.channel { try? await self.sendEvent("pusher:unsubscribe", data: ["channel": old]) }
                        }
                    }
                } catch { if !Task.isCancelled { self.reconnect(current) } }
            }
            return
        }
        subscriptionTask = Task { [weak self] in
            guard let self else { return }
            do {
                struct Auth: Decodable { let auth: String; let channel: String; let expires_in: Int?; let control_token: String?; let control_channel: String?; let control_auth: String?; let feed_channel: String?; let feed_auth: String? }
                let auth = try await self.request("rooms/\(room.room_id)/auth", body: ["socket_id": socketId, "protocol": 2, "messages": self.onMessage != nil && self.roomVisible != false], as: Auth.self)
                guard !self.closed, current == self.generation, revision == self.roomRevision, !Task.isCancelled else { return }
                guard self.matchesChannel(auth.channel, room.channel) else { throw ChatFailure(message: "Chat channel mismatch") }
                self.controlToken = auth.control_token
                if let next = auth.control_channel, let signature = auth.control_auth {
                    if let old = self.controlChannel, old != next { try? await self.sendEvent("pusher:unsubscribe", data: ["channel": old]) }
                    self.controlChannel = next
                    self.controlSubscribed = false
                    try await self.sendEvent("pusher:subscribe", data: ["channel": next, "auth": signature])
                }
                if self.feedChannel != auth.feed_channel {
                    self.clearFeed(); self.feedChannel = auth.feed_channel
                    if let feed = auth.feed_channel, let signature = auth.feed_auth {
                        try await self.sendEvent("pusher:subscribe", data: ["channel": feed, "auth": signature])
                    }
                }
                let old = self.channel
                self.channel = auth.channel
                self.subscriptionExpiry = ProcessInfo.processInfo.systemUptime + Double(min(300, max(1, auth.expires_in ?? 300))) + ((self.batchAuthorization || auth.control_token != nil) ? Double.random(in: 0...5) : 0)
                if old == auth.channel && self.isConnected { return }
                self.isConnected = false
                if let old, old != auth.channel { try? await self.sendEvent("pusher:unsubscribe", data: ["channel": old]) }
                try await self.sendEvent("pusher:subscribe", data: ["channel": auth.channel, "auth": auth.auth])
            } catch {
                guard !Task.isCancelled, revision == self.roomRevision else { return }
                if let failure = error as? ChatFailure, [403, 404, 410].contains(failure.statusCode ?? 0) {
                    await self.revokeRoom()
                } else { self.reconnect(current) }
            }
        }
    }

    public func history(roomId: String, before: String? = nil, after: String? = nil) async throws -> ChatPage {
        let query = before.map { "?before=\($0)" } ?? after.map { "?after=\($0)" } ?? ""
        return try await request("rooms/\(roomId)/messages\(query)", as: ChatPage.self)
    }

    private func clearFeed() {
        if let feedChannel { Task { try? await self.sendEvent("pusher:unsubscribe", data: ["channel": feedChannel]) } }
        feedChannel = nil; feedSubscribed = false
    }

    private func recoverLiveMessage(_ id: String, kind: String) {
        if liveKinds[id] == kind || liveKinds[id] == "deleted" { return }
        pendingLive[id] = kind
        while pendingLive.count > 500, let first = pendingLive.keys.first { pendingLive.removeValue(forKey: first) }
        guard liveRecoveryTask == nil else { return }
        let revision = roomRevision
        let delay = liveRecoveryDelay
        liveRecoveryTask = Task { [weak self] in
            do { try await Task.sleep(nanoseconds: UInt64(delay * 1_000_000_000)) } catch { return }
            guard let self else { return }
            self.liveRecoveryTask = nil
            guard self.roomRevision == revision, !self.closed, self.roomVisible != false, !self.pendingLive.isEmpty else { return }
            let deleted = self.pendingLive.values.contains("deleted")
            self.pendingLive.removeAll(); self.liveRecoveryDelay = min(30, self.liveRecoveryDelay * 2)
            self.onChanged?(deleted ? "reconcile" : "created", nil)
        }
    }

    private func connect() {
        guard !closed, let room, let url = URL(string: room.websocket_url), url.scheme == "wss" else { onConnection?(false); return }
        generation += 1; let current = generation
        hasSubscribedRoom = false
        retryTask?.cancel(); subscriptionTask?.cancel()
        socketId = nil; channel = nil; controlToken = nil; controlChannel = nil; controlSubscribed = false; clearFeed(); socketURL = room.websocket_url
        unreadSubscribed.removeAll(); unreadPhysical.removeAll(); unreadExpiry.removeAll(); subscriptionExpiry = 0
        socket?.cancel(with: .goingAway, reason: nil); receiveTask?.cancel()
        var request = URLRequest(url: url)
        request.setValue(baseURL.absoluteString.trimmingCharacters(in: CharacterSet(charactersIn: "/")), forHTTPHeaderField: "Origin")
        let ws = ChatSocketPool.open(request: request, owner: owner ?? "", namespace: socketPoolNamespace, configuration: session.configuration); socket = ws; ws.resume()
        receiveTask = Task { [weak self] in
            do {
                while !Task.isCancelled {
                    let message = try await ws.receive()
                    guard let self, !self.closed, current == self.generation else { return }
                    guard self.credentials()?.ownerKey == self.owner else { self.close(); return }
                    let raw: Data
                    switch message { case .string(let text): raw = Data(text.utf8); case .data(let data): raw = data; @unknown default: continue }
                    guard let event = try JSONSerialization.jsonObject(with: raw) as? [String: Any], let name = event["event"] as? String else { continue }
                    let payload: [String: Any]
                    if let text = event["data"] as? String { payload = (try? JSONSerialization.jsonObject(with: Data(text.utf8))) as? [String: Any] ?? [:] }
                    else { payload = event["data"] as? [String: Any] ?? [:] }
                    switch name {
                    case "pusher:connection_established":
                        guard let socketId = payload["socket_id"] as? String else { continue }
                        self.socketId = socketId
                        self.subscribe()
                    case "pusher_internal:subscription_succeeded":
                        if let control = self.controlChannel, event["channel"] as? String == control {
                            self.controlSubscribed = true
                            if let room = self.room, let visible = self.roomVisible { self.reportVisibility(room.room_id, visible: visible) }
                            continue
                        }
                        if let feed = self.feedChannel, event["channel"] as? String == feed {
                            self.feedSubscribed = true; self.onChanged?("sync", nil); continue
                        }
                        if let targets = self.unreadChannels {
                            guard let physical = event["channel"] as? String,
                                  let target = self.unreadPhysical.first(where: { $0.value == physical })?.key,
                                  targets.contains(target) else { continue }
                            self.unreadSubscribed.insert(target)
                            if !self.isConnected && self.unreadSubscribed.isSuperset(of: targets) {
                                self.retry = 0; self.isConnected = true; self.onConnection?(true); self.onChanged?("sync", nil)
                            }
                            continue
                        }
                        guard let channel = self.channel, event["channel"] as? String == channel else { continue }
                        let recovery = !self.hasSubscribedRoom
                        self.hasSubscribedRoom = true
                        self.retry = 0; self.isConnected = true; self.onConnection?(true)
                        self.onChanged?(recovery ? "sync" : "subscribed", nil)
                    case "pusher:ping": try await ws.send(.string("{\"event\":\"pusher:pong\",\"data\":{}}"))
                    case "chat:presence_ack":
                        if event["channel"] as? String == self.controlChannel,
                           payload["visibility_id"] as? String == self.visibilityID,
                           let sequence = payload["visibility_sequence"] as? NSNumber {
                            self.visibilityAcknowledged = max(self.visibilityAcknowledged, sequence.int64Value)
                        }
                    case "chat.message":
                        guard self.feedSubscribed, event["channel"] as? String == self.feedChannel,
                              payload["room_id"] as? String == self.room?.room_id,
                              let raw = payload["message"] as? [String: Any],
                              let version = (payload["event_id"] as? String).flatMap(UInt64.init),
                              let data = try? JSONSerialization.data(withJSONObject: raw),
                              let message = try? JSONDecoder().decode(ChatMessage.self, from: data),
                              version > (self.liveVersions[message.id] ?? 0),
                              self.liveKinds[message.id] != "deleted" || message.deleted else { continue }
                        self.liveVersions[message.id] = version; self.liveKinds[message.id] = payload["kind"] as? String
                        self.pendingLive.removeValue(forKey: message.id); self.liveRecoveryDelay = 2.5
                        while self.liveVersions.count > 500, let first = self.liveVersions.keys.first {
                            self.liveVersions.removeValue(forKey: first); self.liveKinds.removeValue(forKey: first)
                        }
                        // Apply state before rendering the card so its action
                        // does not wait for the separate metadata event.
                        if let watch = payload["watch"] as? [String: Any] { self.onWatchState?(watch) }
                        self.onMessage?(message)
                    case "chat.changed", "chat.badge":
                        if let targets = self.unreadChannels {
                            guard let physical = event["channel"] as? String,
                                  let target = self.unreadPhysical.first(where: { $0.value == physical })?.key,
                                  targets.contains(target), self.unreadSubscribed.contains(target) else { continue }
                            if let badge = payload["badge"] as? [String: Any] { self.onBadgeEvent?(badge) }
                            continue
                        }
                        guard let channel = self.channel, event["channel"] as? String == channel else { continue }
                        let kind = payload["kind"] as? String ?? "sync"
                        if let invite = payload["room_invite"] as? [String: Any] { self.onRoomInviteState?(invite) }
                        if let watch = payload["watch"] as? [String: Any] {
                            self.onWatchState?(watch)
                            if self.onWatchState != nil && ["watch_control", "watch_status"].contains(kind) { continue }
                        }
                        if kind == "deleted", let id = payload["message_id"] as? String { self.liveKinds[id] = "deleted" }
                        if self.feedSubscribed, kind == "created", let id = payload["message_id"] as? String {
                            self.recoverLiveMessage(id, kind: kind); continue
                        }
                        self.onChanged?(kind, payload["message_id"] as? String)
                    case "pusher:error": throw ChatFailure(message: "Chat connection failed")
                    default: break
                    }
                }
            } catch {
                guard let self, !self.closed, current == self.generation else { return }
                self.reconnect(current)
            }
        }
    }

    private func reconnect(_ current: Int) {
        guard !closed, current == generation else { return }
        generation += 1; let waiting = generation
        subscriptionTask?.cancel(); receiveTask?.cancel()
        socket?.cancel(with: .goingAway, reason: nil); socket = nil
        socketId = nil; channel = nil
        unreadSubscribed.removeAll(); unreadPhysical.removeAll(); unreadExpiry.removeAll(); subscriptionExpiry = 0
        isConnected = false
        onConnection?(false)
        let delay = min(30.0, pow(2.0, Double(retry))) + Double.random(in: 0...0.5)
        retry += 1
        retryTask?.cancel()
        retryTask = Task { [weak self] in
            do { try await Task.sleep(nanoseconds: UInt64(delay * 1_000_000_000)) } catch { return }
            guard let self, self.generation == waiting else { return }
            self.connect()
        }
    }

    public func close() {
        if let room, roomVisible != nil { reportVisibility(room.room_id, visible: false) }
        isConnected = false
        closed = true; generation += 1; roomRevision += 1
        subscriptionTask?.cancel(); channel = nil; socketId = nil; room = nil
        receiveTask?.cancel(); retryTask?.cancel(); syncTask?.cancel(); recoveryTask?.cancel(); liveRecoveryTask?.cancel(); liveRecoveryTask = nil
        socket?.cancel(with: .goingAway, reason: nil); socket = nil
        session.invalidateAndCancel()
        onChanged = nil; onConnection = nil
    }
}
