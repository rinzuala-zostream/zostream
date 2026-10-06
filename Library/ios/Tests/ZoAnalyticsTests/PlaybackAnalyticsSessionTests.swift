import Foundation
import XCTest
@testable import ZoAnalytics

final class ManualClock: @unchecked Sendable {
    var uptime: TimeInterval = 100
    var date = Date(timeIntervalSince1970: 1_700_000_000)

    func advance(_ seconds: TimeInterval) {
        uptime += seconds
        date = date.addingTimeInterval(seconds)
    }
}

final class PlaybackAnalyticsSessionTests: XCTestCase {
    func testDisabledClientDoesNotPersistPlayback() async throws {
        let directory = FileManager.default.temporaryDirectory
            .appendingPathComponent(UUID().uuidString, isDirectory: true)
        defer { try? FileManager.default.removeItem(at: directory) }
        let client = ZoAnalyticsClient(
            queueDirectory: directory,
            collectionEnabled: false
        )
        let clock = ManualClock()
        let summary = makeSession(id: "disabled-session", clock: clock)
            .finish(reason: .userClosed)

        try await client.saveLocally(summary, ownerKey: "owner")
        let counts = await client.pendingCounts(ownerKey: "owner")
        let enabled = await client.isCollectionEnabled()

        XCTAssertEqual(counts.playback, 0)
        XCTAssertFalse(enabled)
    }

    func testLifecycleEventsAreAcceptedByTheContract() {
        let names = [
            "app_opened",
            "app_foregrounded",
            "app_backgrounded",
            "app_session_ended"
        ]

        for name in names {
            XCTAssertEqual(ProductEvent(name: name, appSessionId: "session-1").name, name)
        }
    }

    func testPlaybackSummaryCountsOnlyPlayingTimeAndMergesRanges() throws {
        let clock = ManualClock()
        let session = makeSession(id: "session-1", clock: clock)

        session.recordPlay(positionMs: 0)
        clock.advance(10)
        session.tick(positionMs: 10_000, durationMs: 100_000)
        session.recordPause(positionMs: 10_000)
        clock.advance(5)
        session.tick(positionMs: 10_000, durationMs: 100_000)
        session.recordPlay(positionMs: 10_000)
        clock.advance(10)
        session.tick(positionMs: 20_000, durationMs: 100_000)

        let summary = session.finish(reason: .userClosed)
        XCTAssertEqual(summary.payload.timing.watchedMs, 20_000)
        XCTAssertEqual(summary.payload.timing.uniqueWatchedMs, 20_000)
        XCTAssertEqual(summary.payload.interaction.pauseCount, 1)
        XCTAssertEqual(summary.payload.interaction.resumeCount, 1)
        XCTAssertFalse(summary.payload.result.completed)
    }

    func testSeekingDoesNotIncreaseUniqueWatchTime() throws {
        let clock = ManualClock()
        let session = makeSession(id: "session-2", clock: clock)

        session.recordPlay(positionMs: 0)
        clock.advance(5)
        session.tick(positionMs: 5_000, durationMs: 100_000)
        session.recordSeek(fromMs: 5_000, toMs: 95_000)
        clock.advance(1)
        session.tick(positionMs: 96_000, durationMs: 100_000)

        let summary = session.finish(reason: .userClosed)
        XCTAssertEqual(summary.payload.timing.uniqueWatchedMs, 6_000)
        XCTAssertEqual(summary.payload.interaction.seekForwardMs, 90_000)
        XCTAssertFalse(summary.payload.result.completed)
    }

    func testNaturalEndMarksCompletion() throws {
        let clock = ManualClock()
        let session = makeSession(id: "session-3", clock: clock, type: .episode)
        session.recordPlay(positionMs: 0)
        clock.advance(5)
        session.recordNaturalEnd(positionMs: 5_000, durationMs: 60_000)

        let summary = session.finish(reason: .playerDestroyed)
        XCTAssertTrue(summary.payload.result.completed)
        XCTAssertEqual(summary.payload.endReason, .completed)
    }

    func testPayloadUsesSnakeCaseContract() throws {
        let clock = ManualClock()
        let session = makeSession(id: "session-json", clock: clock)
        let data = try AnalyticsCoding.encoder().encode(session.finish(reason: .userClosed).payload)
        let object = try XCTUnwrap(JSONSerialization.jsonObject(with: data) as? [String: Any])
        XCTAssertNotNil(object["schema_version"])
        XCTAssertNotNil(object["started_at"])
        let timing = try XCTUnwrap(object["timing"] as? [String: Any])
        XCTAssertNotNil(timing["watch_position_ms"])
    }

    func testQueueKeepsNewestSessionRevision() async throws {
        let directory = FileManager.default.temporaryDirectory
            .appendingPathComponent(UUID().uuidString, isDirectory: true)
        defer { try? FileManager.default.removeItem(at: directory) }
        let queue = AnalyticsQueue(
            fileURL: directory.appendingPathComponent("queue.json"),
            maxPendingSessions: 5,
            retentionDays: 7
        )
        let clock = ManualClock()
        let session = makeSession(id: "queued-session", clock: clock)
        let revisionOne = session.checkpoint()
        let revisionTwo = session.checkpoint()
        try await queue.upsert(revisionTwo, ownerKey: "owner", now: clock.date)
        try await queue.upsert(revisionOne, ownerKey: "owner", now: clock.date)

        let pending = await queue.duePlayback(ownerKey: "owner", limit: 10, now: clock.date)
        XCTAssertEqual(pending.count, 1)
        XCTAssertEqual(pending.first?.summary.payload.revision, 2)
    }

    private func makeSession(
        id: String,
        clock: ManualClock,
        type: PlaybackContentType = .movie
    ) -> PlaybackAnalyticsSession {
        PlaybackAnalyticsSession(
            sessionId: id,
            content: PlaybackContent(id: "content-1", type: type),
            context: AnalyticsContext(appVersion: "1.0", buildNumber: "1"),
            now: { clock.date },
            uptime: { clock.uptime }
        )
    }
}
