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
}
private struct ChatEnvelope<T: Decodable>: Decodable {
    let success: Bool
    let data: T?
    let message: String?
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
        let result = try JSONDecoder().decode(ChatEnvelope<T>.self, from: data)
        guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode), result.success, let value = result.data else {
            throw ChatFailure(message: result.message ?? "Chat request failed")
        }
        return value
    }

    public func join(type: String, contentId: String?) async throws -> ChatRoom {
        owner = credentials()?.ownerKey
        if type == "invite", let contentId {
            let info = try await request("invite-rooms/\(contentId)/join", body: [:], as: ChatRoom.self)
            room = info; connect()
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
        var body: [String: Any] = ["type": type]
        if let contentId { body["content_id"] = contentId }
        let info = try await request("rooms/join", body: body, as: ChatRoom.self)
        room = info; connect()
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

    public func history(roomId: String, before: String? = nil, after: String? = nil) async throws -> ChatPage {
        let query = before.map { "?before=\($0)" } ?? after.map { "?after=\($0)" } ?? ""
        return try await request("rooms/\(roomId)/messages\(query)", as: ChatPage.self)
    }

    private func connect() {
        guard !closed, let room, let url = URL(string: room.websocket_url), url.scheme == "wss" else { onConnection?(false); return }
        generation += 1; let current = generation
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
                        struct Auth: Decodable { let auth: String; let channel: String }
                        guard let socketId = payload["socket_id"] as? String else { continue }
                        let auth = try await self.request("rooms/\(room.room_id)/auth", body: ["socket_id": socketId], as: Auth.self)
                        let data = try JSONSerialization.data(withJSONObject: ["event": "pusher:subscribe", "data": ["channel": auth.channel, "auth": auth.auth]])
                        try await ws.send(.string(String(decoding: data, as: UTF8.self)))
                    case "pusher_internal:subscription_succeeded":
                        self.retry = 0; self.onConnection?(true); self.onChanged?("sync", nil)
                    case "pusher:ping": try await ws.send(.string("{\"event\":\"pusher:pong\",\"data\":{}}"))
                    case "chat.changed": self.onChanged?(payload["kind"] as? String ?? "sync", payload["message_id"] as? String)
                    case "pusher:error": throw ChatFailure(message: "Chat connection failed")
                    default: break
                    }
                }
            } catch {
                guard let self, !self.closed, current == self.generation else { return }
                self.onConnection?(false)
                let delay = min(30.0, pow(2.0, Double(self.retry))) + Double.random(in: 0...0.5)
                self.retry += 1
                self.retryTask?.cancel()
                self.retryTask = Task { [weak self] in
                    do { try await Task.sleep(nanoseconds: UInt64(delay * 1_000_000_000)) } catch { return }
                    self?.connect()
                }
            }
        }
    }

    public func close() {
        closed = true; generation += 1
        receiveTask?.cancel(); retryTask?.cancel(); syncTask?.cancel()
        socket?.cancel(with: .goingAway, reason: nil); socket = nil
        session.invalidateAndCancel()
        onChanged = nil; onConnection = nil
    }
}
