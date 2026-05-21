import Foundation

/// Models the push request body. Each table has a bucket with three lists.
/// Cells are `JSONValue` so the same struct can carry every table's columns.
struct PushPayload: Encodable {
    let games: PushBucket
    let items: PushBucket
    let gameCompletions: PushBucket
    let gameImages: PushBucket
    let itemImages: PushBucket

    enum CodingKeys: String, CodingKey {
        case games, items
        case gameCompletions = "game_completions"
        case gameImages = "game_images"
        case itemImages = "item_images"
    }
}

struct PushBucket: Encodable {
    let new: [[String: JSONValue]]
    let modified: [[String: JSONValue]]
    let deleted: [[String: JSONValue]]

    static let empty = PushBucket(new: [], modified: [], deleted: [])
}

/// Server sync endpoints.
struct SyncAPI {
    let client: APIClient

    /// 5-minute timeout for sync calls. The default URLSession 60 s isn't
    /// enough for a full-pull on large accounts (hundreds of games +
    /// completions + images can take well over a minute for the server
    /// to query + serialize). Same ceiling applied to push.
    private static let syncTimeout: TimeInterval = 300

    /// GET /api/v2/sync/changes.php?since=<ISO8601 UTC>.
    /// `since == nil` means full pull (server treats missing param as epoch).
    func fetchChanges(since: Date?) async throws -> ChangesResponseDTO {
        var query: [String: String] = [:]
        if let since {
            query["since"] = Self.iso8601UTC(since)
        }
        return try await client.get("/api/v2/sync/changes.php",
                                    query: query,
                                    timeout: Self.syncTimeout)
    }

    /// POST /api/v2/sync/push.php with JSON body.
    func push(_ payload: PushPayload) async throws -> PushResponseDTO {
        return try await client.postJSON("/api/v2/sync/push.php",
                                         body: payload,
                                         timeout: Self.syncTimeout)
    }

    /// Format: 2026-05-21T10:30:00Z (no fractional seconds — server accepts both).
    private static func iso8601UTC(_ date: Date) -> String {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        f.timeZone = TimeZone(identifier: "UTC")
        return f.string(from: date)
    }
}
