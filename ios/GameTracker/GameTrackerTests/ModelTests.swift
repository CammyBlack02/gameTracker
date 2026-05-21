import XCTest
import SwiftData
@testable import GameTracker

final class ModelTests: XCTestCase {

    func test_game_roundtrips_through_swiftData() throws {
        let (_, context) = try InMemoryContainer.make()
        let g = Game(title: "Halo: Reach", platform: "Xbox 360")
        g.starRating = 9
        context.insert(g)
        try context.save()

        let fetched = try context.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(fetched.count, 1)
        XCTAssertEqual(fetched.first?.title, "Halo: Reach")
        XCTAssertEqual(fetched.first?.starRating, 9)
        XCTAssertEqual(fetched.first?.syncState, .localNew)
    }

    func test_sync_state_persists_as_raw_string() throws {
        let (_, context) = try InMemoryContainer.make()
        let g = Game(title: "A", platform: "B")
        g.syncState = .conflict
        context.insert(g)
        try context.save()

        let fetched = try context.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(fetched.first?.syncStateRaw, "conflict")
        XCTAssertEqual(fetched.first?.syncState, .conflict)
    }

    func test_clientId_is_unique() throws {
        let (_, context) = try InMemoryContainer.make()
        let g1 = Game(title: "A", platform: "B")
        let g2 = Game(title: "C", platform: "D")
        XCTAssertNotEqual(g1.clientId, g2.clientId)
    }

    func test_sync_metadata_singleton_round_trips() throws {
        let (_, context) = try InMemoryContainer.make()
        let meta = SyncMetadata(lastSyncedAt: Date(timeIntervalSince1970: 1_700_000_000),
                                userId: 42)
        context.insert(meta)
        try context.save()

        let fetched = try context.fetch(FetchDescriptor<SyncMetadata>())
        XCTAssertEqual(fetched.count, 1)
        XCTAssertEqual(fetched.first?.userId, 42)
        XCTAssertEqual(fetched.first?.lastSyncedAt?.timeIntervalSince1970, 1_700_000_000)
    }
}
