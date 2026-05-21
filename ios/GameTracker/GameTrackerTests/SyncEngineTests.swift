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
