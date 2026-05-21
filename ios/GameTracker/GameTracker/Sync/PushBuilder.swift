import Foundation
import SwiftData

/// Walks the SwiftData store and assembles a `PushPayload` of every row
/// whose `syncState` is `localNew`, `localModified`, or `localDeleted`.
/// `synced` and `conflict` rows are skipped.
struct PushBuilder {
    let context: ModelContext

    func build() throws -> PushPayload {
        return PushPayload(
            games: try bucketGames(),
            items: try bucketItems(),
            gameCompletions: try bucketCompletions(),
            gameImages: try bucketGameImages(),
            itemImages: try bucketItemImages()
        )
    }

    // MARK: - Per-table buckets

    private func bucketGames() throws -> PushBucket {
        let all = try context.fetch(FetchDescriptor<Game>())
        var new: [[String: JSONValue]] = []
        var modified: [[String: JSONValue]] = []
        var deleted: [[String: JSONValue]] = []
        for g in all {
            switch g.syncState {
            case .localNew:
                new.append(gameToNewRow(g))
            case .localModified where g.serverId != nil:
                modified.append(gameToModifiedRow(g))
            case .localDeleted where g.serverId != nil:
                deleted.append(["server_id": .int(g.serverId!)])
            default: break
            }
        }
        return PushBucket(new: new, modified: modified, deleted: deleted)
    }

    private func bucketItems() throws -> PushBucket {
        let all = try context.fetch(FetchDescriptor<Item>())
        var new: [[String: JSONValue]] = []
        var modified: [[String: JSONValue]] = []
        var deleted: [[String: JSONValue]] = []
        for i in all {
            switch i.syncState {
            case .localNew:
                new.append(itemToNewRow(i))
            case .localModified where i.serverId != nil:
                modified.append(itemToModifiedRow(i))
            case .localDeleted where i.serverId != nil:
                deleted.append(["server_id": .int(i.serverId!)])
            default: break
            }
        }
        return PushBucket(new: new, modified: modified, deleted: deleted)
    }

    private func bucketCompletions() throws -> PushBucket {
        let all = try context.fetch(FetchDescriptor<GameCompletion>())
        var new: [[String: JSONValue]] = []
        var modified: [[String: JSONValue]] = []
        var deleted: [[String: JSONValue]] = []
        for c in all {
            switch c.syncState {
            case .localNew:
                new.append(completionToNewRow(c))
            case .localModified where c.serverId != nil:
                modified.append(completionToModifiedRow(c))
            case .localDeleted where c.serverId != nil:
                deleted.append(["server_id": .int(c.serverId!)])
            default: break
            }
        }
        return PushBucket(new: new, modified: modified, deleted: deleted)
    }

    private func bucketGameImages() throws -> PushBucket {
        let all = try context.fetch(FetchDescriptor<GameImage>())
        var new: [[String: JSONValue]] = []
        var deleted: [[String: JSONValue]] = []
        for g in all {
            switch g.syncState {
            case .localNew:
                // Parent game must already have a server_id; otherwise defer
                // until next sync after the parent is pushed.
                guard let gid = g.gameServerId else { continue }
                new.append([
                    "client_id": .string(g.clientId.uuidString),
                    "game_id":   .int(gid),
                    "image_path": .string(g.imagePath),
                ])
            case .localDeleted where g.serverId != nil:
                deleted.append(["server_id": .int(g.serverId!)])
            default: break
            }
        }
        return PushBucket(new: new, modified: [], deleted: deleted)
    }

    private func bucketItemImages() throws -> PushBucket {
        let all = try context.fetch(FetchDescriptor<ItemImage>())
        var new: [[String: JSONValue]] = []
        var deleted: [[String: JSONValue]] = []
        for i in all {
            switch i.syncState {
            case .localNew:
                guard let iid = i.itemServerId else { continue }
                new.append([
                    "client_id": .string(i.clientId.uuidString),
                    "item_id":   .int(iid),
                    "image_path": .string(i.imagePath),
                ])
            case .localDeleted where i.serverId != nil:
                deleted.append(["server_id": .int(i.serverId!)])
            default: break
            }
        }
        return PushBucket(new: new, modified: [], deleted: deleted)
    }

    // MARK: - Row builders

    private func gameToNewRow(_ g: Game) -> [String: JSONValue] {
        var d: [String: JSONValue] = [
            "client_id": .string(g.clientId.uuidString),
            "title":    .string(g.title),
            "platform": .string(g.platform),
            "played":   .int(g.played),
            "is_physical": .int(g.isPhysical),
        ]
        addOptional(&d, "genre", g.genre)
        addOptional(&d, "description", g.gameDescription)
        addOptional(&d, "series", g.series)
        addOptional(&d, "special_edition", g.specialEdition)
        addOptional(&d, "condition", g.conditionValue)
        addOptional(&d, "review", g.review)
        addOptionalInt(&d, "star_rating", g.starRating)
        addOptionalInt(&d, "metacritic_rating", g.metacriticRating)
        addOptionalDouble(&d, "price_paid", g.pricePaid)
        addOptionalDouble(&d, "pricecharting_price", g.pricechartingPrice)
        addOptional(&d, "digital_store", g.digitalStore)
        addOptional(&d, "front_cover_image", g.frontCoverImage)
        addOptional(&d, "back_cover_image", g.backCoverImage)
        addOptional(&d, "release_date", g.releaseDate.flatMap(Self.formatYMD))
        return d
    }

    private func gameToModifiedRow(_ g: Game) -> [String: JSONValue] {
        var d = gameToNewRow(g)
        d.removeValue(forKey: "client_id")
        d["server_id"] = .int(g.serverId!)
        d["last_synced_at"] = .string(Self.iso8601UTC(g.lastSyncedAt ?? Date(timeIntervalSince1970: 0)))
        return d
    }

    private func itemToNewRow(_ i: Item) -> [String: JSONValue] {
        var d: [String: JSONValue] = [
            "client_id": .string(i.clientId.uuidString),
            "title":    .string(i.title),
            "category": .string(i.category),
            "quantity": .int(i.quantity),
        ]
        addOptional(&d, "platform", i.platform)
        addOptional(&d, "description", i.itemDescription)
        addOptional(&d, "condition", i.conditionValue)
        addOptional(&d, "front_image", i.frontImage)
        addOptional(&d, "back_image", i.backImage)
        addOptional(&d, "notes", i.notes)
        addOptionalDouble(&d, "price_paid", i.pricePaid)
        addOptionalDouble(&d, "pricecharting_price", i.pricechartingPrice)
        return d
    }

    private func itemToModifiedRow(_ i: Item) -> [String: JSONValue] {
        var d = itemToNewRow(i)
        d.removeValue(forKey: "client_id")
        d["server_id"] = .int(i.serverId!)
        d["last_synced_at"] = .string(Self.iso8601UTC(i.lastSyncedAt ?? Date(timeIntervalSince1970: 0)))
        return d
    }

    private func completionToNewRow(_ c: GameCompletion) -> [String: JSONValue] {
        var d: [String: JSONValue] = [
            "client_id": .string(c.clientId.uuidString),
            "title": .string(c.title),
        ]
        addOptionalInt(&d, "game_id", c.gameServerId)
        addOptional(&d, "platform", c.platform)
        addOptional(&d, "time_taken", c.timeTaken)
        addOptional(&d, "date_started", c.dateStarted.flatMap(Self.formatYMD))
        addOptional(&d, "date_completed", c.dateCompleted.flatMap(Self.formatYMD))
        addOptionalInt(&d, "completion_year", c.completionYear)
        addOptional(&d, "notes", c.notes)
        return d
    }

    private func completionToModifiedRow(_ c: GameCompletion) -> [String: JSONValue] {
        var d = completionToNewRow(c)
        d.removeValue(forKey: "client_id")
        d["server_id"] = .int(c.serverId!)
        d["last_synced_at"] = .string(Self.iso8601UTC(c.lastSyncedAt ?? Date(timeIntervalSince1970: 0)))
        return d
    }

    // MARK: - Encoding helpers

    private func addOptional(_ d: inout [String: JSONValue], _ key: String, _ v: String?) {
        if let v { d[key] = .string(v) }
    }
    private func addOptionalInt(_ d: inout [String: JSONValue], _ key: String, _ v: Int?) {
        if let v { d[key] = .int(v) }
    }
    private func addOptionalDouble(_ d: inout [String: JSONValue], _ key: String, _ v: Double?) {
        if let v { d[key] = .double(v) }
    }

    private static func iso8601UTC(_ date: Date) -> String {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        f.timeZone = TimeZone(identifier: "UTC")
        return f.string(from: date)
    }

    private static func formatYMD(_ d: Date) -> String {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = TimeZone(identifier: "UTC")
        f.dateFormat = "yyyy-MM-dd"
        return f.string(from: d)
    }
}
