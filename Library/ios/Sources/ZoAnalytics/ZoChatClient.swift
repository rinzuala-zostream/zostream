import Foundation

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
}
public struct ChatPage: Decodable, Sendable {
    public let messages: [ChatMessage]
    public let has_more: Bool
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
}
public struct ChatFailure: LocalizedError {
    public let message: String
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
    private var socket: URLSessionWebSocketTask?
    private var receiveTask: Task<Void, Never>?
    private var retryTask: Task<Void, Never>?
    private var syncTask: Task<Void, Never>?
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
    public var onChanged: ((String, String?) -> Void)?
    public var onConnection: ((Bool) -> Void)?

    public init(baseURL: URL, version: String, credentials: @escaping () -> AnalyticsCredentials?, playbackToken: @escaping () -> String? = { nil }, refreshCredentials: ((String) async throws -> Void)? = nil, session: URLSession? = nil) {
        self.refreshCredentials = refreshCredentials
        self.baseURL = baseURL; self.version = version; self.credentials = credentials; self.playbackToken = playbackToken
        let config = URLSessionConfiguration.ephemeral
        config.timeoutIntervalForRequest = 15
        config.timeoutIntervalForResource = 20
        self.session = session ?? URLSession(configuration: config)
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
            throw ChatFailure(message: message ?? "Chat request failed")
        }
        let payload = json["data"] as? [String: Any] ?? json["result"] as? [String: Any] ?? json
        return try JSONDecoder().decode(T.self, from: JSONSerialization.data(withJSONObject: payload))
    }

    public func join(type: String, contentId: String?) async throws -> ChatRoom {
        let identity = credentials()?.ownerKey
        guard !closed, owner == nil || owner == identity else {
            throw ChatFailure(message: "Account changed. Reopen chat.")
        }
        owner = identity
        roomRevision += 1; let revision = roomRevision
        subscriptionTask?.cancel()
        let previousChannel = channel
        channel = nil; room = nil
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
        if socket != nil, socketURL == info.websocket_url { subscribe() }
        else { connect() }
        syncTask?.cancel()
        syncTask = Task { [weak self] in
            while !Task.isCancelled {
                do { try await Task.sleep(nanoseconds: 30_000_000_000) } catch { return }
                guard let self, !self.closed else { return }
                if self.credentials()?.ownerKey != self.owner { self.close(); return }
                if let room = self.room {
                    struct Presence: Decodable { let online_count: Int }
                    let _: Presence? = try? await self.request("rooms/\(room.room_id)/heartbeat", body: [:], as: Presence.self)
                }
                self.onChanged?("sync", nil)
            }
        }
        return info
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
        subscriptionTask = Task { [weak self] in
            guard let self else { return }
            do {
                struct Auth: Decodable { let auth: String; let channel: String }
                let auth = try await self.request("rooms/\(room.room_id)/auth", body: ["socket_id": socketId], as: Auth.self)
                guard !self.closed, current == self.generation, revision == self.roomRevision, !Task.isCancelled else { return }
                guard auth.channel == room.channel else { throw ChatFailure(message: "Chat channel mismatch") }
                self.channel = auth.channel
                try await self.sendEvent("pusher:subscribe", data: ["channel": auth.channel, "auth": auth.auth])
            } catch {
                guard !Task.isCancelled, revision == self.roomRevision else { return }
                self.reconnect(current)
            }
        }
    }

    public func history(roomId: String, before: String? = nil, after: String? = nil) async throws -> ChatPage {
        let query = before.map { "?before=\($0)" } ?? after.map { "?after=\($0)" } ?? ""
        return try await request("rooms/\(roomId)/messages\(query)", as: ChatPage.self)
    }

    private func connect() {
        guard !closed, let room, let url = URL(string: room.websocket_url), url.scheme == "wss" else { onConnection?(false); return }
        generation += 1; let current = generation
        retryTask?.cancel(); subscriptionTask?.cancel()
        socketId = nil; channel = nil; socketURL = room.websocket_url
        socket?.cancel(with: .goingAway, reason: nil); receiveTask?.cancel()
        var request = URLRequest(url: url)
        request.setValue(baseURL.absoluteString.trimmingCharacters(in: CharacterSet(charactersIn: "/")), forHTTPHeaderField: "Origin")
        let ws = session.webSocketTask(with: request); socket = ws; ws.resume()
        receiveTask = Task { [weak self] in
            do {
                while !Task.isCancelled {
                    let message = try await ws.receive()
                    guard let self, !self.closed, current == self.generation else { return }
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
                        guard let channel = self.channel, event["channel"] as? String == channel else { continue }
                        self.retry = 0; self.onConnection?(true); self.onChanged?("sync", nil)
                    case "pusher:ping": try await ws.send(.string("{\"event\":\"pusher:pong\",\"data\":{}}"))
                    case "chat.changed":
                        guard let channel = self.channel, event["channel"] as? String == channel else { continue }
                        self.onChanged?(payload["kind"] as? String ?? "sync", payload["message_id"] as? String)
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
        closed = true; generation += 1; roomRevision += 1
        subscriptionTask?.cancel(); channel = nil; socketId = nil; room = nil
        receiveTask?.cancel(); retryTask?.cancel(); syncTask?.cancel()
        socket?.cancel(with: .goingAway, reason: nil); socket = nil
        session.invalidateAndCancel()
        onChanged = nil; onConnection = nil
    }
}
