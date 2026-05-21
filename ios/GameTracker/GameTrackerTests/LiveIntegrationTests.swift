import XCTest
import SwiftData
@testable import GameTracker

/// Hits a real PHP dev server + test database. To run:
///
/// In the web-app repo:
///   bash tests/v2/setup-test-db.sh
///   php -S localhost:8000 router.php
///
/// In Xcode: edit the GameTracker scheme → Test → Environment Variables:
///   GT_LIVE_TEST       = 1
///   GT_SERVER_BASE_URL = http://localhost:8000
///   GT_TEST_USERNAME   = testuser
///   GT_TEST_PASSWORD   = test_password
///
/// Then ⌘U. Without those env vars the test is skipped.
final class LiveIntegrationTests: XCTestCase {

    private var baseURL: URL!
    private var username: String!
    private var password: String!

    override func setUpWithError() throws {
        let env = ProcessInfo.processInfo.environment
        guard env["GT_LIVE_TEST"] == "1" else {
            throw XCTSkip("Set GT_LIVE_TEST=1 to run live integration tests")
        }
        guard let urlStr = env["GT_SERVER_BASE_URL"],
              let url = URL(string: urlStr),
              let u = env["GT_TEST_USERNAME"],
              let p = env["GT_TEST_PASSWORD"]
        else {
            throw XCTSkip("Set GT_SERVER_BASE_URL, GT_TEST_USERNAME, GT_TEST_PASSWORD")
        }
        baseURL = url
        username = u
        password = p
    }

    @MainActor
    func test_full_sync_round_trip() async throws {
        let (_, ctx) = try InMemoryContainer.make()

        // Login
        var token: String? = nil
        let client = APIClient(baseURL: baseURL, tokenProvider: { token })
        let auth = AuthAPI(client: client)
        let login = try await auth.login(username: username, password: password, deviceName: "xctest")
        token = login.token

        // Sync
        let status = SyncStatus()
        let engine = SyncEngine(context: ctx, syncAPI: SyncAPI(client: client), status: status)
        try await engine.runOnce()

        // Create a new local game and push it
        let g = Game(title: "Sync Test \(UUID().uuidString.prefix(8))", platform: "TestPlatform", syncState: .localNew)
        ctx.insert(g)
        try ctx.save()

        try await engine.runOnce()
        XCTAssertNotNil(g.serverId, "newly-created game should have a server ID after push")
        XCTAssertEqual(g.syncState, .synced)

        // Edit the game and push again
        g.title = g.title + " (edited)"
        g.syncState = .localModified
        try ctx.save()

        try await engine.runOnce()
        XCTAssertEqual(g.syncState, .synced)

        // Clean up: delete the test row
        g.syncState = .localDeleted
        try ctx.save()
        try await engine.runOnce()

        // Revoke
        _ = try await auth.revoke()
    }
}
