import Foundation

public struct ChatFirebaseToken: Decodable, Sendable {
    public let firebase_uid: String
    public let custom_token: String
    public let display_name: String?
    public let is_admin: Bool?
}

@MainActor
public protocol ChatFirebaseSource: AnyObject {
    func authenticate(owner: String, loadToken: @escaping @MainActor @Sendable () async throws -> ChatFirebaseToken) async throws -> String
    func invalidate(owner: String, uid: String)
    func writeTyping(roomID: String, sessionID: String, sequence: Int64, active: Bool) -> Bool
    func observe(path: String, limit: UInt?, afterCursor: UInt64?, onValue: @escaping @MainActor @Sendable ([String: Any]) -> Void,
                 onError: @escaping @MainActor @Sendable () -> Void) -> @MainActor @Sendable () -> Void
}

public extension ChatFirebaseSource {
    func writeTyping(roomID: String, sessionID: String, sequence: Int64, active: Bool) -> Bool { false }
}

struct ChatFirebaseLease: Decodable {
    let room_projection_refreshed: Bool
    let refresh_after_seconds: Int
}

struct ChatFirebaseSync: Decodable {
    struct Event: Decodable {
        let kind: String
        let cursor: UInt64
        let message_id: String?
        let message: ChatMessage?
    }
    let cursor_expired: Bool
    let next_cursor: UInt64
    let has_more: Bool
    let events: [Event]
}

@MainActor
final class ChatFirebaseStream {
    private let source: ChatFirebaseSource
    private let owner: String
    private let roomID: String?
    private let active: () -> Bool
    private let token: @MainActor @Sendable () async throws -> ChatFirebaseToken
    private let lease: (String?) async throws -> ChatFirebaseLease
    private let sync: (String, UInt64) async throws -> ChatFirebaseSync
    private let connection: (Bool) -> Void
    private let changed: (String, String?) -> Void
    private let message: (ChatMessage, Bool) -> Void
    private let typing: ([ChatTypist]) -> Void
    private var observations: [@MainActor @Sendable () -> Void] = []
    private var lifecycle: Task<Void, Never>?
    private var drainTask: Task<Void, Never>?
    private var generation = 0
    private var closed = false
    private var connected = false
    private var pending = false
    private var wasObserved = false
    private var observedCursor: UInt64
    private var overlayEvents: Set<UInt64> = []
    private var accountKeys: Set<String>?
    private var roomStateChanged = false
    private var roomEvents: [UInt64: [String: Any]] = [:]
    private var messages: [String: [String: Any]] = [:]
    private var needsRecovery = true
    private var disconnectRevision = 0
    private var lastDrainAt = -1.0
    private var retrySeconds = 1
    private(set) var cursor: UInt64

    init(source: ChatFirebaseSource, owner: String, roomID: String?, cursor: UInt64,
         active: @escaping () -> Bool, token: @escaping @MainActor @Sendable () async throws -> ChatFirebaseToken,
         lease: @escaping (String?) async throws -> ChatFirebaseLease,
         sync: @escaping (String, UInt64) async throws -> ChatFirebaseSync,
         connection: @escaping (Bool) -> Void, changed: @escaping (String, String?) -> Void,
         message: @escaping (ChatMessage, Bool) -> Void, typing: @escaping ([ChatTypist]) -> Void) {
        self.source = source; self.owner = owner; self.roomID = roomID; self.cursor = cursor; observedCursor = cursor
        self.active = active; self.token = token; self.lease = lease; self.sync = sync
        self.connection = connection; self.changed = changed; self.message = message; self.typing = typing
    }

    private func valid(_ run: Int) -> Bool { !closed && generation == run && active() }

    func start() {
        let run = generation
        lifecycle = Task { [weak self] in
            guard let self, self.valid(run) else { return }
            do {
                let uid = try await self.source.authenticate(owner: self.owner, loadToken: self.token)
                guard self.valid(run) else { return }
                while !Task.isCancelled && self.valid(run) {
                    let grant: ChatFirebaseLease
                    do {
                        grant = try await self.lease(self.roomID)
                    } catch let failure as ChatFailure where failure.statusCode == 401 {
                        self.source.invalidate(owner: self.owner, uid: uid)
                        throw failure
                    }
                    guard self.valid(run) else { return }
                    if self.roomID != nil && !grant.room_projection_refreshed {
                        self.close()
                        self.changed("access_denied", nil)
                        return
                    }
                    if self.observations.isEmpty { self.attach(uid, run: run) }
                    let seconds = max(1, min(180, grant.refresh_after_seconds))
                    try await Task.sleep(nanoseconds: UInt64(seconds) * 1_000_000_000)
                }
            } catch {
                if !Task.isCancelled && self.valid(run) { self.recover() }
            }
        }
    }

    private func attach(_ uid: String, run: Int) {
        let failed: @MainActor @Sendable () -> Void = { [weak self] in
            if let self, self.valid(run) {
                self.source.invalidate(owner: self.owner, uid: uid)
                self.recover()
            }
        }
        let path = roomID.map { "chat_v1/user_sync_events/\(uid)/\($0)" } ?? "chat_v1/user_account_events/\(uid)"
        observations.append(source.observe(path: path, limit: 100, afterCursor: roomID == nil ? nil : cursor, onValue: { [weak self] snapshot in
            guard let self, self.valid(run) else { return }
            self.retrySeconds = 1
            if self.roomID == nil {
                let keys = Set(snapshot.keys)
                let updates = self.accountKeys.map { keys.subtracting($0) } ?? keys.filter { (UInt64($0) ?? 0) > self.cursor }
                let initial = self.accountKeys == nil
                self.accountKeys = keys
                if updates.contains(where: { (snapshot[$0] as? [String: Any])?["kind"] as? String == "room_state_invalidated" }) {
                    self.roomStateChanged = true
                }
                if initial || !updates.isEmpty { self.scheduleDrain() }
                return
            }
            var newest = self.cursor
            for (key, value) in snapshot {
                guard let event = UInt64(key), let item = value as? [String: Any] else { continue }
                if event > self.cursor { self.roomEvents[event] = item }
                newest = max(newest, event)
                if event > self.observedCursor && self.connected && self.wasObserved,
                   item["overlay_live"] as? Bool == true {
                    self.overlayEvents.insert(event)
                }
            }
            if self.roomEvents.count > 100 {
                for key in self.roomEvents.keys.sorted().dropLast(100) { self.roomEvents.removeValue(forKey: key) }
                self.needsRecovery = true
            }
            self.wasObserved = true
            if newest > self.observedCursor {
                self.observedCursor = newest
                self.scheduleDrain()
            }
        }, onError: failed))
        observations.append(source.observe(path: ".info/connected", limit: nil, afterCursor: nil, onValue: { [weak self] snapshot in
            guard let self, self.valid(run), let value = snapshot["value"] as? Bool, self.connected != value else { return }
            self.connected = value
            self.connection(value)
            if value { self.scheduleDrain() }
            else {
                self.disconnectRevision += 1
                self.needsRecovery = true
                self.overlayEvents.removeAll()
                self.wasObserved = false
                if self.roomID == nil { self.roomStateChanged = true }
                self.typing([])
            }
        }, onError: failed))
        if let roomID {
            observations.append(source.observe(path: "chat_v1/user_messages/\(uid)/\(roomID)", limit: 30, afterCursor: cursor, onValue: { [weak self] snapshot in
                guard let self, self.valid(run) else { return }
                var records: [String: [String: Any]] = [:]
                for value in snapshot.values {
                    if let record = value as? [String: Any], let id = record["id"] as? String { records[id] = record }
                }
                self.messages = records
            }, onError: failed))
            observations.append(source.observe(path: "chat_v1/room_typing/\(roomID)", limit: 20, afterCursor: nil, onValue: { [weak self] snapshot in
                guard let self, self.valid(run) else { return }
                let now = Date().timeIntervalSince1970 * 1_000
                let entries: [ChatTypist] = snapshot.compactMap { _, value in
                    guard let item = value as? [String: Any], let expiry = item["expires_at"] as? NSNumber,
                          let sender = item["user_id"] as? String, sender != uid, expiry.doubleValue > now else { return nil }
                    return ChatTypist(user_id: sender, name: item["name"] as? String ?? "Viewer",
                                     is_admin: item["is_admin"] as? Bool,
                                     expires_in: Int(ceil((expiry.doubleValue - now) / 1_000)))
                }
                self.typing(Dictionary(entries.map { ($0.user_id, $0) }, uniquingKeysWith: { a, b in (a.expires_in ?? 0) > (b.expires_in ?? 0) ? a : b }).values.sorted { $0.user_id < $1.user_id })
            }, onError: failed))
        }
    }

    func updateAccountCursor(_ value: UInt64) {
        if roomID == nil { cursor = max(cursor, value) }
    }

    private func scheduleDrain(delay: Double = 0.3) {
        pending = true
        guard drainTask == nil, connected else { return }
        let run = generation
        let sinceLast = ProcessInfo.processInfo.systemUptime - lastDrainAt
        drainTask = Task { [weak self] in
            do { try await Task.sleep(nanoseconds: UInt64(max(delay, 1 - sinceLast) * 1_000_000_000)) } catch { return }
            guard let self, self.valid(run) else { return }
            await self.drain(run)
        }
    }

    private func drain(_ run: Int) async {
        guard connected && pending else { drainTask = nil; return }
        pending = false
        lastDrainAt = ProcessInfo.processInfo.systemUptime
        guard let roomID else {
            drainTask = nil
            let kind = roomStateChanged ? "room_state_invalidated" : "unread_invalidated"
            roomStateChanged = false
            changed(kind, nil)
            return
        }
        if !needsRecovery {
            for eventCursor in roomEvents.keys.sorted() where eventCursor > cursor {
                guard let event = roomEvents[eventCursor], let kind = event["kind"] as? String else {
                    needsRecovery = true
                    break
                }
                let id = event["message_id"] as? String
                let carriesMessage = kind == "created" || kind == "deleted"
                let payload = carriesMessage ? id.flatMap { liveMessage(messages[$0], cursor: eventCursor) } : nil
                if carriesMessage && payload == nil {
                    needsRecovery = true
                    break
                }
                dispatch(kind, id: id, eventCursor: eventCursor, payload: payload)
                guard valid(run) else { return }
                cursor = eventCursor
                roomEvents.removeValue(forKey: eventCursor)
            }
            if !needsRecovery { drainTask = nil; return }
        }
        let recoveryStartedAt = disconnectRevision
        do {
            let response = try await sync(roomID, cursor)
            guard valid(run) else { return }
            if response.cursor_expired { changed("reconcile", nil) }
            for event in response.events {
                dispatch(event.kind, id: event.message_id, eventCursor: event.cursor, payload: event.message)
                guard valid(run) else { return }
            }
            cursor = response.next_cursor
            overlayEvents = overlayEvents.filter { $0 > cursor }
            roomEvents = roomEvents.filter { $0.key > cursor }
            needsRecovery = response.has_more || recoveryStartedAt != disconnectRevision
            retrySeconds = 1
            drainTask = nil
            // Live events can arrive before the initial recovery request starts.
            // Drain anything newer than its response cursor as well.
            if response.has_more || pending || !roomEvents.isEmpty { scheduleDrain() }
        } catch {
            guard valid(run) else { return }
            drainTask = nil
            scheduleDrain(delay: Double(retrySeconds))
            retrySeconds = min(30, retrySeconds * 2)
        }
    }

    private func liveMessage(_ record: [String: Any]?, cursor: UInt64) -> ChatMessage? {
        guard let record, (record["cursor"] as? NSNumber)?.uint64Value == cursor, record["reply_to"] == nil,
              let sender = record["sender_id"] as? String, let createdAt = record["created_at_iso"] as? String else { return nil }
        var fields = record
        fields["user_id"] = sender
        fields["display_name"] = record["sender_name"]
        fields["is_admin"] = record["sender_is_admin"]
        fields["request_id"] = record["client_op_id"]
        fields["created_at"] = createdAt
        fields["movie_suggestion"] = record["suggestion"]
        fields["room_invite"] = record["invitation"]
        guard let data = try? JSONSerialization.data(withJSONObject: fields) else { return nil }
        return try? JSONDecoder().decode(ChatMessage.self, from: data)
    }

    private func dispatch(_ kind: String, id: String?, eventCursor: UInt64, payload: ChatMessage?) {
        switch (kind, payload) {
        case ("created", .some(let item)): message(item, overlayEvents.remove(eventCursor) != nil)
        case ("deleted", .some(let item)): message(item, false)
        case ("room_invite_status", _): changed("sync", id)
        case ("read", _): break
        default: changed(kind, id)
        }
    }

    private func recover() {
        reset()
        connection(false)
        let run = generation
        let delay = retrySeconds
        retrySeconds = min(30, retrySeconds * 2)
        lifecycle = Task { [weak self] in
            do { try await Task.sleep(nanoseconds: UInt64(delay) * 1_000_000_000) } catch { return }
            if let self, self.valid(run) { self.start() }
        }
    }

    private func reset() {
        generation += 1
        lifecycle?.cancel(); lifecycle = nil
        drainTask?.cancel(); drainTask = nil
        observations.forEach { $0() }; observations.removeAll()
        connected = false; pending = false; wasObserved = false; overlayEvents.removeAll()
        accountKeys = nil
        roomEvents.removeAll(); messages.removeAll(); needsRecovery = true
    }

    func close() {
        closed = true
        reset()
    }
}
