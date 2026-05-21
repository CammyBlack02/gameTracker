import XCTest
import SwiftData
@testable import GameTracker

final class PushBuilderTests: XCTestCase {

    func test_localNew_game_appears_in_new_bucket() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let g = Game(title: "New Game", platform: "PC", syncState: .localNew)
        ctx.insert(g)
        try ctx.save()

        let payload = try PushBuilder(context: ctx).build()
        XCTAssertEqual(payload.games.new.count, 1)
        XCTAssertEqual(payload.games.modified.count, 0)
        XCTAssertEqual(payload.games.deleted.count, 0)
        XCTAssertEqual(payload.games.new[0]["title"]?.stringValue, "New Game")
        XCTAssertNotNil(payload.games.new[0]["client_id"]?.stringValue)
    }

    func test_localModified_game_with_serverId_appears_in_modified_bucket() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let g = Game(title: "Edit", platform: "PC", syncState: .localModified)
        g.serverId = 42
        g.lastSyncedAt = Date(timeIntervalSince1970: 1_700_000_000)
        ctx.insert(g)
        try ctx.save()

        let payload = try PushBuilder(context: ctx).build()
        XCTAssertEqual(payload.games.modified.count, 1)
        XCTAssertEqual(payload.games.modified[0]["server_id"]?.intValue, 42)
        XCTAssertEqual(payload.games.modified[0]["last_synced_at"]?.stringValue,
                       "2023-11-14T22:13:20Z")
    }

    func test_localDeleted_game_with_serverId_appears_in_deleted_bucket() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let g = Game(title: "Gone", platform: "PC", syncState: .localDeleted)
        g.serverId = 7
        ctx.insert(g)
        try ctx.save()

        let payload = try PushBuilder(context: ctx).build()
        XCTAssertEqual(payload.games.deleted.count, 1)
        XCTAssertEqual(payload.games.deleted[0]["server_id"]?.intValue, 7)
    }

    func test_synced_row_is_not_included() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let g = Game(title: "Already synced", platform: "PC", syncState: .synced)
        g.serverId = 1
        ctx.insert(g)
        try ctx.save()

        let payload = try PushBuilder(context: ctx).build()
        XCTAssertEqual(payload.games.new.count, 0)
        XCTAssertEqual(payload.games.modified.count, 0)
        XCTAssertEqual(payload.games.deleted.count, 0)
    }

    func test_conflict_row_is_not_pushed() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let g = Game(title: "Conflicted", platform: "PC", syncState: .conflict)
        g.serverId = 9
        ctx.insert(g)
        try ctx.save()

        let payload = try PushBuilder(context: ctx).build()
        XCTAssertEqual(payload.games.modified.count, 0,
                       "conflicts are not auto-pushed; user must resolve first")
    }
}
