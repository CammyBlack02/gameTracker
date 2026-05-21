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

    // MARK: - Cross-model init defaults

    func test_item_init_defaults_syncState_to_localNew() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let i = Item(title: "Wii", category: "console")
        ctx.insert(i)
        try ctx.save()
        let fetched = try ctx.fetch(FetchDescriptor<Item>())
        XCTAssertEqual(fetched.first?.syncState, .localNew)
        XCTAssertEqual(fetched.first?.quantity, 1)
    }

    func test_completion_init_defaults_syncState_to_localNew() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let c = GameCompletion(title: "Halo run 1")
        ctx.insert(c)
        try ctx.save()
        let fetched = try ctx.fetch(FetchDescriptor<GameCompletion>())
        XCTAssertEqual(fetched.first?.syncState, .localNew)
    }

    func test_gameImage_init_defaults_syncState_to_localNew() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let img = GameImage(imagePath: "abc.jpg", gameServerId: 42)
        ctx.insert(img)
        try ctx.save()
        let fetched = try ctx.fetch(FetchDescriptor<GameImage>())
        XCTAssertEqual(fetched.first?.syncState, .localNew)
        XCTAssertEqual(fetched.first?.gameServerId, 42)
    }

    func test_itemImage_init_defaults_syncState_to_localNew() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let img = ItemImage(imagePath: "x.jpg", itemServerId: 7)
        ctx.insert(img)
        try ctx.save()
        let fetched = try ctx.fetch(FetchDescriptor<ItemImage>())
        XCTAssertEqual(fetched.first?.syncState, .localNew)
        XCTAssertEqual(fetched.first?.itemServerId, 7)
    }
}
