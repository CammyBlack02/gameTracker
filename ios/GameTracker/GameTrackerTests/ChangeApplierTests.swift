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

    // MARK: - Cover cache invalidation on sync (issue #43)

    /// A real ImagesAPI wired to a temp directory. The invalidate* methods
    /// are pure filesystem ops on the cacheRoot — the APIClient isn't
    /// touched by them, so we can hand it a dummy config.
    private func makeImagesAPI() -> (ImagesAPI, URL) {
        let root = FileManager.default.temporaryDirectory
            .appendingPathComponent("ChangeApplierTests-\(UUID().uuidString)")
        try? FileManager.default.createDirectory(at: root, withIntermediateDirectories: true)
        let client = APIClient(baseURL: URL(string: "http://localhost")!)
        return (ImagesAPI(client: client, cacheRoot: root), root)
    }

    private func seedCoverFile(_ root: URL, filename: String) {
        let path = root.appendingPathComponent(filename)
        try? "stale".data(using: .utf8)!.write(to: path, options: .atomic)
    }

    func test_cover_change_from_sync_invalidates_cached_files() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let (imagesAPI, cacheRoot) = makeImagesAPI()

        // Local game with an existing cover already cached to disk.
        let existing = Game(title: "Halo", platform: "Xbox", syncState: .synced)
        existing.serverId = 7
        existing.frontCoverImage = "old-cover.jpg"
        ctx.insert(existing)
        try ctx.save()

        seedCoverFile(cacheRoot, filename: "cover_7_front_thumb.jpg")
        seedCoverFile(cacheRoot, filename: "cover_7_front_full.jpg")

        // Sync response changes the cover reference.
        let applier = ChangeApplier(context: ctx, imagesAPI: imagesAPI)
        var row = gameRow(id: 7, title: "Halo")
        row["front_cover_image"] = "new-cover.jpg"
        applier.apply(changesResponse(games: [row]))
        try ctx.save()

        // The stale on-disk files should be gone.
        XCTAssertFalse(
            FileManager.default.fileExists(atPath: cacheRoot.appendingPathComponent("cover_7_front_thumb.jpg").path),
            "front thumb should be invalidated when sync changes the cover reference"
        )
        XCTAssertFalse(
            FileManager.default.fileExists(atPath: cacheRoot.appendingPathComponent("cover_7_front_full.jpg").path),
            "front full should be invalidated too"
        )
    }

    func test_cover_unchanged_from_sync_leaves_cache_intact() throws {
        let (_, ctx) = try InMemoryContainer.make()
        let (imagesAPI, cacheRoot) = makeImagesAPI()

        let existing = Game(title: "Halo", platform: "Xbox", syncState: .synced)
        existing.serverId = 8
        existing.frontCoverImage = "same-cover.jpg"
        ctx.insert(existing)
        try ctx.save()

        seedCoverFile(cacheRoot, filename: "cover_8_front_thumb.jpg")

        // Sync response returns the same cover reference (some other field changed).
        let applier = ChangeApplier(context: ctx, imagesAPI: imagesAPI)
        var row = gameRow(id: 8, title: "Halo 2")   // title changed, cover didn't
        row["front_cover_image"] = "same-cover.jpg"
        applier.apply(changesResponse(games: [row]))
        try ctx.save()

        XCTAssertTrue(
            FileManager.default.fileExists(atPath: cacheRoot.appendingPathComponent("cover_8_front_thumb.jpg").path),
            "cache must survive syncs that don't touch cover_image (avoid pointless re-downloads)"
        )
    }
}
