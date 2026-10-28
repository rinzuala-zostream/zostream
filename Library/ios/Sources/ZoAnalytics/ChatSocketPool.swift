import Foundation

@MainActor
protocol ChatSocketWire: AnyObject {
    func resume()
    func receive() async throws -> URLSessionWebSocketTask.Message
    func send(_ message: URLSessionWebSocketTask.Message) async throws
    func sendPing(pongReceiveHandler: @escaping @Sendable (Error?) -> Void)
    func cancel(with closeCode: URLSessionWebSocketTask.CloseCode, reason: Data?)
}
extension URLSessionWebSocketTask: ChatSocketWire {}

@MainActor
enum ChatSocketPool {
    static var hubs: [String: ChatSocketHub] = [:]
    static func open(request: URLRequest, owner: String, namespace: String, configuration: URLSessionConfiguration,
                     factory: ((URLRequest) -> any ChatSocketWire)? = nil) -> ChatSocketLease {
        let key = namespace + ":" + owner + ":" + (request.url?.absoluteString ?? "") + ":" + (request.value(forHTTPHeaderField: "Origin") ?? "")
        let hub = hubs[key] ?? ChatSocketHub(key: key, request: request, configuration: configuration, factory: factory)
        hubs[key] = hub
        return hub.attach()
    }
}

@MainActor
final class ChatSocketLease {
    let id = UUID()
    let hub: ChatSocketHub
    private var queue: [URLSessionWebSocketTask.Message] = []
    private var waiter: CheckedContinuation<URLSessionWebSocketTask.Message, Error>?
    private var failure: Error?
    init(hub: ChatSocketHub) { self.hub = hub }
    func resume() { hub.start() }
    func receive() async throws -> URLSessionWebSocketTask.Message {
        if let failure { throw failure }
        if !queue.isEmpty { return queue.removeFirst() }
        return try await withCheckedThrowingContinuation { waiter = $0 }
    }
    func deliver(_ message: URLSessionWebSocketTask.Message) {
        if let waiter { self.waiter = nil; waiter.resume(returning: message) }
        else if queue.count < 256 { queue.append(message) }
        else { cancel(with: .goingAway, reason: nil) }
    }
    func fail(_ error: Error) {
        failure = error; queue.removeAll()
        waiter?.resume(throwing: error); waiter = nil
    }
    func send(_ message: URLSessionWebSocketTask.Message) async throws { try await hub.send(self, message) }
    func sendPing(pongReceiveHandler: @escaping @Sendable (Error?) -> Void) { hub.ping(pongReceiveHandler) }
    func cancel(with closeCode: URLSessionWebSocketTask.CloseCode, reason: Data?) {
        fail(CancellationError()); hub.release(self)
    }
}

@MainActor
final class ChatSocketHub {
    private let key: String
    private let session: URLSession?
    private let wire: any ChatSocketWire
    private var task: Task<Void, Never>?
    private var members: [UUID: ChatSocketLease] = [:]
    private var channels: [String: Set<UUID>] = [:]
    private var subscribed: [String: URLSessionWebSocketTask.Message] = [:]
    private var handshake: URLSessionWebSocketTask.Message?
    private var dead = false
    private var lastPing: TimeInterval = -.infinity

    init(key: String, request: URLRequest, configuration: URLSessionConfiguration, factory: ((URLRequest) -> any ChatSocketWire)?) {
        self.key = key
        if let factory { session = nil; wire = factory(request) }
        else {
            let session = URLSession(configuration: configuration)
            self.session = session; wire = session.webSocketTask(with: request)
        }
    }
    func attach() -> ChatSocketLease {
        let lease = ChatSocketLease(hub: self); members[lease.id] = lease
        if let handshake { lease.deliver(handshake) }
        return lease
    }
    func start() {
        guard task == nil, !dead else { return }
        wire.resume()
        task = Task { [weak self] in
            guard let self else { return }
            do {
                while !Task.isCancelled {
                    let message = try await self.wire.receive()
                    if case .string(let text) = message,
                       let frame = try? JSONSerialization.jsonObject(with: Data(text.utf8)) as? [String: Any] {
                        if frame["event"] as? String == "pusher:connection_established" { self.handshake = message }
                        if frame["event"] as? String == "pusher_internal:subscription_succeeded", let channel = frame["channel"] as? String {
                            self.subscribed[channel] = message
                        }
                    }
                    for lease in Array(self.members.values) { lease.deliver(message) }
                }
            } catch { self.shutdown(error) }
        }
    }
    func send(_ lease: ChatSocketLease, _ message: URLSessionWebSocketTask.Message) async throws {
        guard !dead, members[lease.id] != nil else { throw CancellationError() }
        if case .string(let text) = message,
           let frame = try? JSONSerialization.jsonObject(with: Data(text.utf8)) as? [String: Any],
           let event = frame["event"] as? String,
           let data = frame["data"] as? [String: Any], let channel = data["channel"] as? String {
            if event == "pusher:subscribe" {
                let already = !(channels[channel] ?? []).isEmpty
                channels[channel, default: []].insert(lease.id)
                if already {
                    if let ack = subscribed[channel] { lease.deliver(ack) }
                    return
                }
            } else if event == "pusher:unsubscribe" {
                channels[channel]?.remove(lease.id)
                if channels[channel]?.isEmpty != true { return }
                channels.removeValue(forKey: channel); subscribed.removeValue(forKey: channel)
            }
        }
        try await wire.send(message)
    }
    func ping(_ callback: @escaping @Sendable (Error?) -> Void) {
        guard !dead else { callback(CancellationError()); return }
        let now = ProcessInfo.processInfo.systemUptime
        if now - lastPing < 25 { callback(nil); return }
        lastPing = now; wire.sendPing(pongReceiveHandler: callback)
    }
    func release(_ lease: ChatSocketLease) {
        guard members.removeValue(forKey: lease.id) != nil else { return }
        for channel in Array(channels.keys) {
            channels[channel]?.remove(lease.id)
            if channels[channel]?.isEmpty == true {
                channels.removeValue(forKey: channel); subscribed.removeValue(forKey: channel)
                let payload = ["event": "pusher:unsubscribe", "data": ["channel": channel]] as [String: Any]
                if let raw = try? JSONSerialization.data(withJSONObject: payload), let text = String(data: raw, encoding: .utf8) {
                    Task { try? await self.wire.send(.string(text)) }
                }
            }
        }
        if members.isEmpty { shutdown(CancellationError()) }
    }
    private func shutdown(_ error: Error) {
        guard !dead else { return }
        dead = true
        if ChatSocketPool.hubs[key] === self { ChatSocketPool.hubs.removeValue(forKey: key) }
        let clients = Array(members.values); members.removeAll(); channels.removeAll(); subscribed.removeAll()
        clients.forEach { $0.fail(error) }
        task?.cancel(); task = nil; wire.cancel(with: .goingAway, reason: nil); session?.invalidateAndCancel()
    }
}
