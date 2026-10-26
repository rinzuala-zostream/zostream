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

final class ChatClientTests: XCTestCase {
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

    func testHistoryDecodesLargeStringIdentifiersAndDeletedMessages() throws {
        let data = Data(#"{"messages":[{"id":"9007199254740993","room_id":"2","user_id":"alice","request_id":"request","display_name":"Alice","body":null,"spoiler":false,"deleted":true,"created_at":"2026-10-24 10:00:00"}],"has_more":true}"#.utf8)
        let page = try JSONDecoder().decode(ChatPage.self,from:data)
        XCTAssertEqual(page.messages.first?.id,"9007199254740993")
        XCTAssertNil(page.messages.first?.body)
        XCTAssertTrue(page.messages.first!.deleted)
        XCTAssertTrue(page.has_more)
    }

    func testOnlyClientChatEventsAreAllowlisted() {
        XCTAssertTrue(ProductEvent.supportedNames.contains("chat_opened"))
        XCTAssertTrue(ProductEvent.supportedNames.contains("chat_closed"))
        XCTAssertFalse(ProductEvent.supportedNames.contains("chat_message_sent"))
        XCTAssertFalse(ProductEvent.supportedNames.contains("chat_message_reported"))
    }
}
