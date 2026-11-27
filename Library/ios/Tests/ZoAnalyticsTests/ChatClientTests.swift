import Foundation
import XCTest
@testable import ZoAnalytics

private final class ChatURLProtocol: URLProtocol {
    static var handle: ((URLRequest) throws -> (Int, String))?
    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }
    override func startLoading() {
        do {
            let (status, body) = try Self.handle!(request)
            client?.urlProtocol(self, didReceive: HTTPURLResponse(url: request.url!, statusCode: status, httpVersion: nil, headerFields: ["Content-Type":"application/json"])!, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: Data(body.utf8))
            client?.urlProtocolDidFinishLoading(self)
        } catch { client?.urlProtocol(self, didFailWithError: error) }
    }
    override func stopLoading() {}
}

@MainActor
private final class FirebaseFixture: ChatFirebaseSource {
    var observers: [String: @MainActor @Sendable ([String: Any]) -> Void] = [:]

    func authenticate(owner: String, loadToken: @escaping @MainActor @Sendable () async throws -> ChatFirebaseToken) async throws -> String { "uid" }
    func invalidate(owner: String, uid: String) {}
    func observe(path: String, limit: UInt?, afterCursor: UInt64?, onValue: @escaping @MainActor @Sendable ([String: Any]) -> Void,
                 onError: @escaping @MainActor @Sendable () -> Void) -> @MainActor @Sendable () -> Void {
        if path.contains("user_messages") || path.contains("user_sync_events") { XCTAssertEqual(afterCursor, 4) }
        observers[path] = onValue
        onValue(path == ".info/connected" ? ["value": true] : [:])
        return { [weak self] in self?.observers.removeValue(forKey: path) }
    }
}

final class ChatClientTests: XCTestCase {
    @MainActor
    func testFirebaseDeliversLiveMessageWithoutAnotherSQLRequestAndDetaches() async {
        let fixture = FirebaseFixture()
        let catchUp = expectation(description: "SQL catch-up")
        let delivered = expectation(description: "RTDB message")
        var syncs = 0
        let stream = ChatFirebaseStream(source: fixture, owner: "owner", roomID: "17", cursor: 4,
            active: { true }, token: { throw CancellationError() },
            lease: { _ in ChatFirebaseLease(room_projection_refreshed: true, refresh_after_seconds: 60) },
            sync: { _, cursor in
                XCTAssertEqual(cursor, 4)
                syncs += 1
                catchUp.fulfill()
                return ChatFirebaseSync(cursor_expired: false, next_cursor: 4, has_more: false, events: [])
            }, connection: { _ in }, changed: { _, _ in }, message: { item, overlay in
                XCTAssertEqual(item.user_id, "bob")
                XCTAssertTrue(overlay)
                delivered.fulfill()
            }, typing: { _ in })
        stream.start()
        await fulfillment(of: [catchUp], timeout: 3)
        fixture.observers["chat_v1/user_messages/uid/17"]?(["00000000000000000501": [
            "id": "501", "room_id": "17", "sender_id": "bob", "sender_name": "#Bob", "client_op_id": "request",
            "body": "Live", "spoiler": false, "deleted": false, "created_at_iso": "2026-11-27T00:00:00Z", "cursor": 5
        ]])
        fixture.observers["chat_v1/user_sync_events/uid/17"]?(["00000000000000000005": [
            "cursor": 5, "kind": "created", "message_id": "501", "overlay_live": true
        ]])
        await fulfillment(of: [delivered], timeout: 3)
        XCTAssertEqual(syncs, 1)
        stream.close()
        XCTAssertTrue(fixture.observers.isEmpty)
    }
    func testMovieSuggestionQueryAndCacheRoundTrip() throws {
        XCTAssertNil(ChatMovieSuggestion.query(in: "/av"))
        XCTAssertEqual(ChatMovieSuggestion.query(in: "/ava"), "ava")
        XCTAssertNil(ChatMovieSuggestion.query(in: "/ava tar"))
        XCTAssertNil(ChatMovieSuggestion.query(in: "/watch"))
        XCTAssertNil(ChatMovieSuggestion.query(in: "/invite"))
        let data = Data(#"{"id":"1","room_id":"2","user_id":"alice","request_id":"r","display_name":"Alice","body":"Movie suggestion: Avatar","spoiler":false,"deleted":false,"created_at":"2026-11-25T00:00:00Z","movie_suggestion":{"id":"movie-1","title":"Avatar","poster":"https://example.test/poster.jpg"}}"#.utf8)
        let message = try JSONDecoder().decode(ChatMessage.self, from: data)
        let saved = try JSONEncoder().encode(message)
        let restored = try JSONDecoder().decode(ChatMessage.self, from: saved)
        XCTAssertEqual(restored.movie_suggestion?.id, "movie-1")
        XCTAssertEqual(restored.movie_suggestion, message.movie_suggestion)
        let old = try JSONDecoder().decode(ChatMessage.self, from: Data(#"{"id":"2","room_id":"2","user_id":"alice","request_id":"old","display_name":"Alice","body":"Old cached message","spoiler":false,"deleted":false,"created_at":"2026-11-25T00:00:00Z"}"#.utf8))
        XCTAssertNil(old.movie_suggestion)
    }

    func testInteractionMetadataDecodesWithoutRevealingSpoilerQuotes() throws {
        let message = try JSONDecoder().decode(ChatMessage.self, from: Data(#"{"id":"1","room_id":"2","user_id":"alice","request_id":"request","display_name":"Alice","body":"Reply","spoiler":false,"deleted":false,"created_at":"2026-10-30T10:00:00Z","reply_to_id":"9","reply":{"id":"9","user_id":"bob","display_name":"Bob","body":null,"spoiler":true,"deleted":false},"reactions":[{"emoji":"😂","count":2}],"my_reactions":["😂"],"moment":{"seconds":755,"content_type":"movie","content_id":"film-a"}}"#.utf8))
        XCTAssertEqual(message.reply_to_id, "9")
        XCTAssertTrue(message.reply?.spoiler == true)
        XCTAssertNil(message.reply?.body)
        XCTAssertEqual(message.reactions?.first?.count, 2)
        XCTAssertEqual(message.my_reactions, ["😂"])
        XCTAssertEqual(message.moment?.seconds, 755)
    }

    @MainActor
    func testInitialHistoryRequestsUnreadPositionAndMessagesTogether() async throws {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [ChatURLProtocol.self]
        ChatURLProtocol.handle = { request in
            XCTAssertEqual(request.url?.path, "/api/v4/chat/rooms/1/messages")
            XCTAssertEqual(request.url?.query, "initial=1")
            return (200, #"{"success":true,"data":{"messages":[],"has_more":false,"first_unread_id":"9","initial_after":"8"}}"#)
        }
        let client = ZoChatClient(baseURL: URL(string: "https://example.test")!, version: "test", credentials: {
            AnalyticsCredentials(accessToken: "token", deviceToken: "device", ownerKey: "alice")
        }, session: URLSession(configuration: configuration))
        defer { client.close() }
        let page = try await client.history(roomId: "1", initial: true)
        XCTAssertEqual(page.first_unread_id, "9")
        XCTAssertEqual(page.initial_after, "8")
        ChatURLProtocol.handle = { request in
            XCTAssertEqual(request.url?.query, "initial=1&latest=1")
            return (200, #"{"success":true,"data":{"messages":[],"has_more":true,"first_unread_id":"9","initial_after":null}}"#)
        }
        let firstPage = try await client.history(roomId: "1", initial: true, latest: true)
        XCTAssertTrue(firstPage.has_more)
        XCTAssertNil(firstPage.initial_after)
        let legacy = try JSONDecoder().decode(ChatPage.self, from: Data(#"{"messages":[],"has_more":false}"#.utf8))
        XCTAssertNil(legacy.first_unread_id)
        XCTAssertNil(legacy.initial_after)
    }

    @MainActor
    func testDisconnectedTranscriptRecoversWithoutReopeningAndStopsOnClose() async throws {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [ChatURLProtocol.self]
        ChatURLProtocol.handle = { _ in
            (200, #"{"success":true,"data":{"room_id":"1","room_type":"global","channel":"private-chat.1","websocket_url":"","user_id":"alice"}}"#)
        }
        let client = ZoChatClient(baseURL: URL(string: "https://example.test")!, version: "test", credentials: {
            AnalyticsCredentials(accessToken: "token", deviceToken: "device", ownerKey: "alice")
        }, session: URLSession(configuration: configuration))
        var polls = 0
        client.onChanged = { kind, _ in if kind == "poll" { polls += 1 } }
        defer { client.close() }
        _ = try await client.join(type: "global", contentId: nil)
        XCTAssertFalse(client.isConnected)
        try await Task.sleep(nanoseconds: 5_200_000_000)
        XCTAssertEqual(polls, 1)
        client.close()
        // Retain an observer to check that close cancels recovery, not just callbacks.
        client.onChanged = { kind, _ in if kind == "poll" { polls += 1 } }
        try await Task.sleep(nanoseconds: 5_200_000_000)
        XCTAssertEqual(polls, 1)
    }

    @MainActor
    func testResponseFormatsMatchAndroidAndPreserveServerErrors() async throws {
        struct Rooms: Decodable { let rooms: [String] }
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [ChatURLProtocol.self]
        let client = ZoChatClient(baseURL: URL(string: "https://example.test")!, version: "test", credentials: {
            AnalyticsCredentials(accessToken: "token", deviceToken: "device", ownerKey: "alice")
        }, session: URLSession(configuration: configuration))
        defer { client.close() }
        for body in [
            #"{"success":true,"data":{"rooms":["1"]}}"#,
            #"{"success":1,"data":{"rooms":["1"]}}"#,
            #"{"success":"true","data":{"rooms":["1"]}}"#,
            #"{"status":"success","result":{"rooms":["1"]}}"#,
            #"{"data":{"rooms":["1"]}}"#,
            #"{"rooms":["1"]}"#,
        ] {
            ChatURLProtocol.handle = { _ in (200, body) }
            let value = try await client.request("invite-rooms", as: Rooms.self)
            XCTAssertEqual(value.rooms, ["1"])
        }
        for (status, body) in [
            (403, #"{"success":false,"data":{},"message":"Room ended"}"#),
            (200, #"{"success":false,"rooms":["1"],"error":{"message":"Room ended"}}"#),
        ] {
            ChatURLProtocol.handle = { _ in (status, body) }
            do {
                let _: Rooms = try await client.request("invite-rooms", as: Rooms.self)
                XCTFail("A failed response must not be decoded as successful data")
            } catch { XCTAssertEqual(error.localizedDescription, "Room ended") }
        }
    }

    @MainActor
    func testExpiredCredentialRefreshRetriesOnceAndKeepsDeviceIdentity() async throws {
        struct Value: Decodable { let ok: Bool }
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [ChatURLProtocol.self]
        var token = "old-token"
        var requests = 0
        ChatURLProtocol.handle = { request in
            requests += 1
            XCTAssertEqual(request.value(forHTTPHeaderField: "Device-Token"), "device-a")
            XCTAssertEqual(request.value(forHTTPHeaderField: "X-Playback-Token"), "play-a")
            XCTAssertEqual(request.url?.path, "/api/v4/chat/rooms/1/messages")
            if requests == 1 { return (401, "{\"success\":false,\"data\":null,\"message\":\"Expired\"}") }
            XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer new-token")
            return (200, "{\"success\":true,\"data\":{\"ok\":true}}")
        }
        let client = ZoChatClient(baseURL: URL(string:"https://example.test")!, version:"test", credentials: {
            AnalyticsCredentials(accessToken:token,deviceToken:"device-a",ownerKey:"alice")
        }, playbackToken: { "play-a" }, refreshCredentials: { failed in
            XCTAssertEqual(failed,"old-token"); token = "new-token"
        }, session: URLSession(configuration:configuration))
        let result = try await client.request("rooms/1/messages", as: Value.self)
        XCTAssertTrue(result.ok); XCTAssertEqual(requests,2)
        client.close()
    }

    @MainActor
    func testTypingStopSurvivesImmediateRoomTransportClose() async throws {
        let delivered = expectation(description: "typing stop delivered")
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [ChatURLProtocol.self]
        ChatURLProtocol.handle = { request in
            XCTAssertEqual(request.url?.path, "/api/v4/chat/rooms/1/typing")
            XCTAssertEqual(request.httpMethod, "POST")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer token")
            var body = request.httpBody ?? Data()
            if let stream = request.httpBodyStream {
                stream.open(); defer { stream.close() }
                var buffer = [UInt8](repeating: 0, count: 1024)
                while stream.hasBytesAvailable { let n = stream.read(&buffer, maxLength: buffer.count); if n <= 0 { break }; body.append(buffer, count: n) }
            }
            let json = try JSONSerialization.jsonObject(with: body) as! [String: Any]
            XCTAssertEqual(json["active"] as? Bool, false)
            XCTAssertEqual(json["typing_sequence"] as? Int, 2)
            delivered.fulfill()
            return (200, #"{"success":true,"data":{"users":[]}}"#)
        }
        let client = ZoChatClient(baseURL: URL(string: "https://example.test")!, version: "test", credentials: {
            AnalyticsCredentials(accessToken: "token", deviceToken: "device", ownerKey: "alice")
        }, session: URLSession(configuration: configuration))
        client.stopTyping(roomID: "1", sessionID: UUID().uuidString, sequence: 2)
        client.close()
        await fulfillment(of: [delivered], timeout: 2)
    }

    func testHistoryDecodesLargeStringIdentifiersAndDeletedMessages() throws {
        let data = Data(#"{"messages":[{"id":"9007199254740993","room_id":"2","user_id":"alice","request_id":"request","display_name":"Alice","body":null,"spoiler":false,"deleted":true,"created_at":"2026-10-24 10:00:00"}],"has_more":true}"#.utf8)
        let page = try JSONDecoder().decode(ChatPage.self,from:data)
        XCTAssertEqual(page.messages.first?.id,"9007199254740993")
        XCTAssertNil(page.messages.first?.body)
        XCTAssertTrue(page.messages.first!.deleted)
        XCTAssertTrue(page.has_more)
    }

    func testPrivateWatchCardAndRoomMetadataDecodeWithoutChangingPrivateRoomType() throws {
        let data = Data(#"{"id":"12","room_id":"2","user_id":"alice","request_id":"request","display_name":"Alice","body":"Watch Film A together","spoiler":false,"deleted":false,"created_at":"2026-10-27T10:00:00Z","watch_invite":{"session_id":"5","content_type":"movie","content_id":"film-a","content_title":"Film A"}}"#.utf8)
        let message = try JSONDecoder().decode(ChatMessage.self, from: data)
        XCTAssertEqual(message.watch_invite?.session_id, "5")
        XCTAssertEqual(message.watch_invite?.content_title, "Film A")
        let roomData = Data(#"{"room_id":"2","room_type":"invite","channel":"private-chat.2","websocket_url":"","user_id":"alice","is_host":true,"content_type":null,"watch_session":{"session_id":"5","content_type":"movie","content_id":"film-a","content_title":"Film A"}}"#.utf8)
        let room = try JSONDecoder().decode(ChatRoom.self, from: roomData)
        XCTAssertNil(room.content_type)
        XCTAssertEqual(room.watch_session?.session_id, "5")
    }

    func testOnlyClientChatEventsAreAllowlisted() {
        XCTAssertTrue(ProductEvent.supportedNames.contains("chat_opened"))
        XCTAssertTrue(ProductEvent.supportedNames.contains("chat_closed"))
        XCTAssertFalse(ProductEvent.supportedNames.contains("chat_message_sent"))
        XCTAssertFalse(ProductEvent.supportedNames.contains("chat_message_reported"))
    }
}
