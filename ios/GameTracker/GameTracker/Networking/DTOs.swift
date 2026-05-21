import Foundation

// MARK: - Envelope types

/// All v2 success responses are wrapped in `{ "data": ... }`.
struct APIEnvelope<T: Decodable>: Decodable {
    let data: T
}

/// All v2 error responses share this shape.
struct APIErrorDTO: Decodable, Error {
    let error: String
    let message: String?
}

// MARK: - Auth

struct TokenResponseDTO: Decodable {
    let token: String
    let userId: Int
    let username: String

    enum CodingKeys: String, CodingKey {
        case token
        case userId = "user_id"
        case username
    }
}

struct RevokeResponseDTO: Decodable {
    let revoked: Bool
}

// MARK: - Sync

/// Row representations returned by /sync/changes. Mirrors the server's
/// MySQL columns. We decode unknown / future columns leniently by not
/// requiring fields that may be absent.
struct GameDTO: Decodable {
    let id: Int
    let userId: Int?
    let title: String
    let platform: String
    let genre: String?
    let description: String?
    let series: String?
    let specialEdition: String?
    let condition: String?
    let review: String?
    let starRating: Int?
    let metacriticRating: Int?
    let played: Int?
    let pricePaid: Double?
    let pricechartingPrice: Double?
    let isPhysical: Int?
    let digitalStore: String?
    let frontCoverImage: String?
    let backCoverImage: String?
    let releaseDate: String?     // server returns "YYYY-MM-DD" or NULL
    let createdAt: String?
    let updatedAt: String        // ISO-ish; parsed in ChangeApplier

    enum CodingKeys: String, CodingKey {
        case id
        case userId = "user_id"
        case title, platform, genre, description, series
        case specialEdition = "special_edition"
        case condition, review
        case starRating = "star_rating"
        case metacriticRating = "metacritic_rating"
        case played
        case pricePaid = "price_paid"
        case pricechartingPrice = "pricecharting_price"
        case isPhysical = "is_physical"
        case digitalStore = "digital_store"
        case frontCoverImage = "front_cover_image"
        case backCoverImage = "back_cover_image"
        case releaseDate = "release_date"
        case createdAt = "created_at"
        case updatedAt = "updated_at"
    }
}

struct ItemDTO: Decodable {
    let id: Int
    let userId: Int?
    let title: String
    let platform: String?
    let category: String
    let description: String?
    let condition: String?
    let pricePaid: Double?
    let pricechartingPrice: Double?
    let frontImage: String?
    let backImage: String?
    let notes: String?
    let quantity: Int?
    let createdAt: String?
    let updatedAt: String

    enum CodingKeys: String, CodingKey {
        case id
        case userId = "user_id"
        case title, platform, category, description, condition
        case pricePaid = "price_paid"
        case pricechartingPrice = "pricecharting_price"
        case frontImage = "front_image"
        case backImage = "back_image"
        case notes, quantity
        case createdAt = "created_at"
        case updatedAt = "updated_at"
    }
}

struct GameCompletionDTO: Decodable {
    let id: Int
    let userId: Int?
    let gameId: Int?
    let title: String
    let platform: String?
    let timeTaken: String?
    let dateStarted: String?
    let dateCompleted: String?
    let completionYear: Int?
    let notes: String?
    let updatedAt: String

    enum CodingKeys: String, CodingKey {
        case id
        case userId = "user_id"
        case gameId = "game_id"
        case title, platform
        case timeTaken = "time_taken"
        case dateStarted = "date_started"
        case dateCompleted = "date_completed"
        case completionYear = "completion_year"
        case notes
        case updatedAt = "updated_at"
    }
}

struct GameImageDTO: Decodable {
    let id: Int
    let gameId: Int
    let imagePath: String
    let updatedAt: String

    enum CodingKeys: String, CodingKey {
        case id
        case gameId = "game_id"
        case imagePath = "image_path"
        case updatedAt = "updated_at"
    }
}

struct ItemImageDTO: Decodable {
    let id: Int
    let itemId: Int
    let imagePath: String
    let updatedAt: String

    enum CodingKeys: String, CodingKey {
        case id
        case itemId = "item_id"
        case imagePath = "image_path"
        case updatedAt = "updated_at"
    }
}

struct DeletionDTO: Decodable {
    let tableName: String
    let serverId: Int
    let deletedAt: String

    enum CodingKeys: String, CodingKey {
        case tableName = "table_name"
        case serverId = "server_id"
        case deletedAt = "deleted_at"
    }
}

struct ChangesResponseDTO: Decodable {
    let games: [GameDTO]
    let items: [ItemDTO]
    let gameCompletions: [GameCompletionDTO]
    let gameImages: [GameImageDTO]
    let itemImages: [ItemImageDTO]
    let deletions: [DeletionDTO]
    let serverNow: Date

    enum CodingKeys: String, CodingKey {
        case games, items, deletions
        case gameCompletions = "game_completions"
        case gameImages = "game_images"
        case itemImages = "item_images"
        case serverNow = "server_now"
    }
}

// MARK: - Push

/// One row in the per-table result array returned by /sync/push.
/// Fields are optional because the shape varies by `result`:
/// - "accepted":  client_id?, server_id, updated_at
/// - "conflict":  server_id, server_version (raw JSON object)
/// - "not_found": server_id
/// - "rejected":  client_id?, server_id?, reason
struct PushRowResultDTO: Decodable {
    let clientId: String?
    let serverId: Int?
    let updatedAt: String?
    let serverVersion: PushServerVersionDTO?
    let result: String
    let reason: String?

    enum CodingKeys: String, CodingKey {
        case clientId = "client_id"
        case serverId = "server_id"
        case updatedAt = "updated_at"
        case serverVersion = "server_version"
        case result, reason
    }
}

/// Untyped server row carried back in a conflict response. We decode it
/// to `[String: JSONValue]` rather than a per-table type because all
/// five tables can show up here.
struct PushServerVersionDTO: Decodable {
    let raw: [String: JSONValue]
    init(from decoder: Decoder) throws {
        raw = try [String: JSONValue](from: decoder)
    }
}

struct PushResponseDTO: Decodable {
    let games: [PushRowResultDTO]
    let items: [PushRowResultDTO]
    let gameCompletions: [PushRowResultDTO]
    let gameImages: [PushRowResultDTO]
    let itemImages: [PushRowResultDTO]

    enum CodingKeys: String, CodingKey {
        case games, items
        case gameCompletions = "game_completions"
        case gameImages = "game_images"
        case itemImages = "item_images"
    }
}

/// A polymorphic JSON value, used when we need to round-trip server data
/// without committing to a static struct. Limited to the shapes MySQL
/// actually returns (string, int, double, bool, null).
enum JSONValue: Codable {
    case string(String), int(Int), double(Double), bool(Bool), null

    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if c.decodeNil() { self = .null; return }
        if let v = try? c.decode(Bool.self) { self = .bool(v); return }
        if let v = try? c.decode(Int.self) { self = .int(v); return }
        if let v = try? c.decode(Double.self) { self = .double(v); return }
        if let v = try? c.decode(String.self) { self = .string(v); return }
        throw DecodingError.dataCorruptedError(in: c, debugDescription: "Unsupported JSON value")
    }

    func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        switch self {
        case .string(let v): try c.encode(v)
        case .int(let v):    try c.encode(v)
        case .double(let v): try c.encode(v)
        case .bool(let v):   try c.encode(v)
        case .null:          try c.encodeNil()
        }
    }

    var stringValue: String? { if case .string(let v) = self { return v } else { return nil } }
    var intValue: Int? {
        switch self {
        case .int(let v):    return v
        case .double(let v): return Int(v)
        case .string(let v): return Int(v)
        default:             return nil
        }
    }
}

// MARK: - JSONDecoder extension

// Cached formatters for `iso8601WithFractional`. Building DateFormatter is
// expensive (~1ms each); a sync/changes response with hundreds of rows
// would thrash allocation if we built them per-decode. Build once.
private let v2DateFormatters: [DateFormatter] = {
    let formats = [
        "yyyy-MM-dd'T'HH:mm:ss'Z'",
        "yyyy-MM-dd'T'HH:mm:ss.SSSXXXXX",
        "yyyy-MM-dd'T'HH:mm:ssXXXXX",
    ]
    return formats.map { fmt in
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = TimeZone(identifier: "UTC")
        f.dateFormat = fmt
        return f
    }
}()

private let v2ISO8601Fallback: ISO8601DateFormatter = {
    let iso = ISO8601DateFormatter()
    iso.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
    return iso
}()

extension JSONDecoder.DateDecodingStrategy {
    /// Accepts `2026-05-21T10:30:00Z`, `2026-05-21T10:30:00+00:00`, and
    /// `2026-05-21T10:30:00.123+00:00`. Matches the variants the server
    /// emits in `sync/changes` responses. Formatters are cached at file
    /// scope to avoid per-decode allocation cost.
    static var iso8601WithFractional: JSONDecoder.DateDecodingStrategy {
        .custom { decoder in
            let s = try decoder.singleValueContainer().decode(String.self)
            for f in v2DateFormatters {
                if let d = f.date(from: s) { return d }
            }
            if let d = v2ISO8601Fallback.date(from: s) { return d }
            throw DecodingError.dataCorruptedError(in: try decoder.singleValueContainer(),
                                                   debugDescription: "Could not parse date: \(s)")
        }
    }
}
