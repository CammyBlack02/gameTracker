import XCTest
import SwiftData
@testable import GameTracker

final class SyncEngineTests: XCTestCase {

    override func setUp() {
        super.setUp()
        URLProtocolStub.reset()
    }

    /// Helper to assemble a SyncEngine wired to in-memory SwiftData + a stubbed APIClient.
    @MainActor
    private func makeEngine() throws -> (SyncEngine, ModelContext) {
        let (_, ctx) = try InMemoryContainer.make()
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let engine = SyncEngine(
            context: ctx,
            syncAPI: SyncAPI(client: client),
            status: SyncStatus()
        )
        return (engine, ctx)
    }

    @MainActor
    func test_runOnce_applies_server_changes_then_pushes_locals() async throws {
        let (engine, ctx) = try makeEngine()

        let localGame = Game(title: "FromPhone", platform: "iOS", syncState: .localNew)
        ctx.insert(localGame)
        try ctx.save()

        // Use the actual local clientId in the push response so the response
        // applier can find the row.
        let cid = localGame.clientId.uuidString
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{
              "games":[{"id":10,"title":"FromServer","platform":"PC","updated_at":"2026-05-21T10:00:00Z"}],
              "items":[],"game_completions":[],"game_images":[],"item_images":[],
              "deletions":[], "server_now":"2026-05-21T10:00:00Z"
            }}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/changes.php") == true }
        ))
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: """
            {"data":{
              "games":[{"client_id":"\(cid)","server_id":99,"updated_at":"2026-05-21T10:00:01Z","result":"accepted"}],
              "items":[],"game_completions":[],"game_images":[],"item_images":[]
            }}
            """.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/push.php") == true }
        ))

        try await engine.runOnce()

        let games = try ctx.fetch(FetchDescriptor<Game>())
        XCTAssertEqual(games.count, 2)
        let serverGame = games.first(where: { $0.title == "FromServer" })
        XCTAssertEqual(serverGame?.serverId, 10)
        XCTAssertEqual(serverGame?.syncState, .synced)

        let pushedGame = games.first(where: { $0.title == "FromPhone" })
        XCTAssertEqual(pushedGame?.serverId, 99)
        XCTAssertEqual(pushedGame?.syncState, .synced)
    }

    @MainActor
    func test_conflict_response_marks_local_row_as_conflict() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{"games":[],"items":[],"game_completions":[],"game_images":[],"item_images":[],"deletions":[],"server_now":"2026-05-21T10:00:00Z"}}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/changes.php") == true }
        ))
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{
              "games":[
                { "server_id": 5, "server_version":{"id":5,"title":"ServerWins","platform":"PC","updated_at":"2026-05-21T11:00:00Z"}, "result":"conflict" }
              ],
              "items":[],"game_completions":[],"game_images":[],"item_images":[]
            }}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/push.php") == true }
        ))

        let (engine, ctx) = try makeEngine()
        let local = Game(title: "PhoneEdit", platform: "PC", syncState: .localModified)
        local.serverId = 5
        local.lastSyncedAt = Date(timeIntervalSince1970: 1700000000)
        ctx.insert(local)
        try ctx.save()

        try await engine.runOnce()

        let games = try ctx.fetch(FetchDescriptor<Game>())
        let g = games.first!
        XCTAssertEqual(g.syncState, .conflict)
        XCTAssertEqual(g.title, "PhoneEdit", "phone version must NOT be auto-overwritten - user resolves")
    }

    // MARK: - Fable §4 Bug 1: deleted games must not resurrect

    @MainActor
    func test_delete_round_trip_hard_deletes_local_row() async throws {
        // Empty pull.
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{"games":[],"items":[],"game_completions":[],"game_images":[],"item_images":[],"deletions":[],"server_now":"2026-05-21T10:00:00Z"}}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/changes.php") == true }
        ))
        // Push: server accepts the deletion.
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{
              "games":[{"server_id":42,"result":"accepted","updated_at":"2026-05-21T10:00:01Z"}],
              "items":[],"game_completions":[],"game_images":[],"item_images":[]
            }}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/push.php") == true }
        ))

        let (engine, ctx) = try makeEngine()
        // Row is tombstoned locally with a known serverId — same state
        // PushBuilder emits from the delete UI.
        let deletedLocally = Game(title: "SoonToBeGone", platform: "PC", syncState: .localDeleted)
        deletedLocally.serverId = 42
        ctx.insert(deletedLocally)
        try ctx.save()

        try await engine.runOnce()

        let games = try ctx.fetch(FetchDescriptor<Game>())
        XCTAssertTrue(
            games.isEmpty,
            "After push acceptance of a delete, the local row must be hard-deleted — not left in .synced (which would resurrect it until the next pull brings a tombstone). Fable §4 Bug 1."
        )
    }

    // MARK: - Fable §4 Bug 3: conflict must persist server_version

    @MainActor
    func test_conflict_response_persists_server_version_on_local_row() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{"games":[],"items":[],"game_completions":[],"game_images":[],"item_images":[],"deletions":[],"server_now":"2026-05-21T10:00:00Z"}}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/changes.php") == true }
        ))
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{
              "games":[
                { "server_id": 7, "server_version":{"id":7,"title":"ServerTitle","platform":"PC","updated_at":"2026-05-21T11:00:00Z"}, "result":"conflict" }
              ],
              "items":[],"game_completions":[],"game_images":[],"item_images":[]
            }}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/push.php") == true }
        ))

        let (engine, ctx) = try makeEngine()
        let local = Game(title: "PhoneTitle", platform: "PC", syncState: .localModified)
        local.serverId = 7
        ctx.insert(local)
        try ctx.save()

        try await engine.runOnce()

        let g = try ctx.fetch(FetchDescriptor<Game>()).first
        XCTAssertEqual(g?.syncState, .conflict)
        XCTAssertNotNil(g?.serverVersionJSON, "server_version blob must be persisted so ConflictDetailView can show it. Fable §4 Bug 3.")
        XCTAssertTrue((g?.serverVersionJSON ?? "").contains("ServerTitle"), "server_version blob must contain the server title")
    }

    // MARK: - Fable §4 Bug 2: "Keep server" applies server data

    @MainActor
    func test_keep_server_version_replaces_local_fields() async throws {
        let (_, ctx) = try InMemoryContainer.make()

        // Stash a server_version on a locally-modified game with a
        // different title/platform. This is the state after a conflict.
        let local = Game(title: "PhoneTitle", platform: "iOS", syncState: .conflict)
        local.serverId = 11
        local.serverVersionJSON = #"""
        {"id":11,"title":"ServerTitle","platform":"PC","updated_at":"2026-05-21T11:00:00Z"}
        """#
        ctx.insert(local)
        try ctx.save()

        // Simulate the user hitting "Keep server version".
        ChangeApplier(context: ctx).applyStoredServerVersion(to: local)

        XCTAssertEqual(local.title, "ServerTitle", "Keep-server must overwrite the local title from the stashed server_version. Fable §4 Bug 2.")
        XCTAssertEqual(local.platform, "PC")
        XCTAssertEqual(local.syncState, .synced)
        XCTAssertNil(local.serverVersionJSON, "server_version blob is cleared once resolved")
    }

    @MainActor
    func test_runOnce_updates_status_to_idle_on_success() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{"games":[],"items":[],"game_completions":[],"game_images":[],"item_images":[],"deletions":[],"server_now":"2026-05-21T10:00:00Z"}}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/changes.php") == true }
        ))
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"games":[],"items":[],"game_completions":[],"game_images":[],"item_images":[]}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/push.php") == true }
        ))
        let (engine, _) = try makeEngine()
        try await engine.runOnce()
        XCTAssertEqual(engine.status.phase, .idle)
    }
}
