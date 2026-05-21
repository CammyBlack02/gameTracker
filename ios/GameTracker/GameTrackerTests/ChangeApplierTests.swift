import XCTest
import SwiftData
@testable import GameTracker

final class ChangeApplierTests: XCTestCase {

    /// Minimal game-row JSON — only the required fields. Returns the
    /// dictionary so it can be embedded in a larger `ChangesResponseDTO`
    /// envelope without round-tripping through `JSONEncoder` (the DTOs
    /// are decode-only).
    private func gameRow(id: Int,
                         title: String,
                         updatedAt: String = "2026-05-21T10:00:00Z") -> [String: Any] {
        return [
            "id": id,
            "title": title,
            "platform": "P",
            "updated_at": updatedAt,
        ]
    }

    private func changesResponse(games: [[String: Any]] = [],
                                 deletions: [DeletionDTO] = [],
                                 serverNow: String = "2026-05-21T10:00:00Z") -> ChangesResponseDTO {
        let body: [String: Any] = [
            "games": games,
            "items": [],
            "game_completions": [],
            "game_images": [],
            "item_images": [],
            "deletions": deletions.map {
                ["table_name": $0.tableName, "server_id": $0.serverId, "deleted_at": $0.deletedAt]
            },
            "server_now": serverNow,
        ]
        let envelope: [String: Any] = ["data": body]
        let data = try! JSONSerialization.data(withJSONObject: envelope)
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601WithFractional
        return try! decoder.decode(APIEnvelope<ChangesResponseDTO>.self, from: data).data
    }

    func test_new_server_row_creates_local_game() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let applier = ChangeApplier(context: ctx)

        applier.apply(changesResponse(games: [gameRow(id: 1, title: "Halo")]))
        try ctx.save()

        let games = try ctx.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(games.count, 1)
        XCTAssertEqual(games.first?.title, "Halo")
        XCTAssertEqual(games.first?.serverId, 1)
        XCTAssertEqual(games.first?.syncState, .synced)
    }

    func test_existing_server_row_updates_local_game() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let existing = Game(title: "Old Title", platform: "P", syncState: .synced)
        existing.serverId = 5
        ctx.insert(existing)
        try ctx.save()

        let applier = ChangeApplier(context: ctx)
        applier.apply(changesResponse(games: [gameRow(id: 5, title: "New Title")]))
        try ctx.save()

        let games = try ctx.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(games.count, 1, "should update, not duplicate")
        XCTAssertEqual(games.first?.title, "New Title")
    }

    func test_locally_modified_row_is_not_clobbered_unless_local_synced() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let existing = Game(title: "Local Edit", platform: "P", syncState: .localModified)
        existing.serverId = 5
        ctx.insert(existing)
        try ctx.save()

        let applier = ChangeApplier(context: ctx)
        applier.apply(changesResponse(games: [gameRow(id: 5, title: "Server Edit")]))
        try ctx.save()

        let games = try ctx.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(games.first?.title, "Local Edit",
                       "applier must not overwrite local pending edits")
    }

    func test_deletion_removes_local_synced_row() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let existing = Game(title: "Doomed", platform: "P", syncState: .synced)
        existing.serverId = 99
        ctx.insert(existing)
        try ctx.save()

        let applier = ChangeApplier(context: ctx)
        applier.apply(changesResponse(deletions: [
            DeletionDTO(tableName: "games", serverId: 99, deletedAt: "2026-05-21T10:00:00Z")
        ]))
        try ctx.save()

        let games = try ctx.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(games.count, 0)
    }
}
