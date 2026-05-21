import Foundation

/// Wraps the four "passthrough" v2 endpoints (PriceCharting, Metacritic,
/// external-image, cover-upload). The server's response shape varies per
/// upstream service, so we return `[String: JSONValue]` and let callers
/// cherry-pick fields.
struct ProxiesAPI {

    enum Face: String { case front, back }

    let client: APIClient

    /// GET /api/v2/pricecharting.php?title=&platform=
    func priceCharting(title: String, platform: String) async throws -> [String: JSONValue] {
        let env: PassthroughDTO = try await client.get(
            "/api/v2/pricecharting.php",
            query: ["title": title, "platform": platform])
        return env.raw
    }

    /// GET /api/v2/metacritic.php?title=&platform=
    func metacritic(title: String, platform: String) async throws -> [String: JSONValue] {
        let env: PassthroughDTO = try await client.get(
            "/api/v2/metacritic.php",
            query: ["title": title, "platform": platform])
        return env.raw
    }

    /// GET /api/v2/external-image.php?url=&game_id=&type=front|back
    /// Server downloads the URL into uploads/covers/ and updates the games row.
    /// Returns the saved path (we don't typically need it — the next sync will pull
    /// down the row with the new front_cover_image column).
    func externalImage(url: String, gameId: Int, face: Face) async throws -> [String: JSONValue] {
        let env: PassthroughDTO = try await client.get(
            "/api/v2/external-image.php",
            query: ["url": url, "game_id": String(gameId), "type": face.rawValue])
        return env.raw
    }

    /// POST /api/v2/games/cover-upload.php?game_id=&face=…  (multipart "image" field)
    func uploadCover(gameId: Int,
                     face: Face,
                     imageData: Data,
                     filename: String,
                     mimeType: String = "image/jpeg") async throws -> [String: JSONValue] {
        let env: PassthroughDTO = try await client.uploadImage(
            "/api/v2/games/cover-upload.php",
            query: ["game_id": String(gameId), "face": face.rawValue],
            imageData: imageData,
            filename: filename,
            mimeType: mimeType)
        return env.raw
    }
}

/// Untyped envelope payload used by all four proxies. The server's actual
/// response keys depend on the upstream service; we decode into JSONValue
/// so callers can use `.stringValue` / `.intValue` accessors.
private struct PassthroughDTO: Decodable {
    let raw: [String: JSONValue]
    init(from decoder: Decoder) throws {
        raw = try [String: JSONValue](from: decoder)
    }
}
