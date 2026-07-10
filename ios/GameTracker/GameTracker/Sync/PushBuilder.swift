import Foundation
import SwiftData

/// Walks the SwiftData store and assembles a `PushPayload` of every row
/// whose `syncState` is `localNew`, `localModified`, or `localDeleted`.
/// `synced` and `conflict` rows are skipped.
struct PushBuilder {
    let context: ModelContext

    func build() throws -> PushPayload {
        return PushPayload(
            games:           try bucket(of: Game.self,           toNew: gameToNewRow,       toModified: gameToModifiedRow),
            items:           try bucket(of: Item.self,           toNew: itemToNewRow,       toModified: itemToModifiedRow),
            gameCompletions: try bucket(of: GameCompletion.self, toNew: completionToNewRow, toModified: completionToModifiedRow),
            gameImages:      try bucket(of: GameImage.self,      toNew: gameImageToNewRow),
            itemImages:      try bucket(of: ItemImage.self,      toNew: itemImageToNewRow)
        )
    }

    // MARK: - Generic bucketing

    /// Walk every row of type T and drop it into new/modified/deleted
    /// according to its syncState. Fable §4 (last item): five per-type
    /// `bucketX` functions collapsed here.
    ///
    /// `toNew` returns `nil` for a row that can't be pushed yet (e.g.
    /// an image whose parent hasn't been server-side'd — see the
    /// image builders below); those rows are silently skipped and
    /// picked up on the next sync.
    ///
    /// `toModified` is `nil` for row types that are immutable
    /// server-side (image tables — no `modified` bucket at all).
    private func bucket<T: SyncableModel>(
        of _: T.Type,
        toNew: (T) -> [String: JSONValue]?,
        toModified: ((T) -> [String: JSONValue])? = nil
    ) throws -> PushBucket {
        let all = try context.fetch(FetchDescriptor<T>())
        var new: [[String: JSONValue]] = []
        var modified: [[String: JSONValue]] = []
        var deleted: [[String: JSONValue]] = []
        for row in all {
            switch row.syncStateRaw {
            case SyncState.localNew.rawValue:
                if let dict = toNew(row) { new.append(dict) }
            case SyncState.localModified.rawValue where row.serverId != nil:
                if let toMod = toModified {
                    modified.append(toMod(row))
                }
            case SyncState.localDeleted.rawValue where row.serverId != nil:
                deleted.append(["server_id": .int(row.serverId!)])
            default:
                break
            }
        }
        return PushBucket(new: new, modified: modified, deleted: deleted)
    }

    // MARK: - Row builders

    /// `toNew` closures return an optional dict — non-image types
    /// always return a value, so Swift auto-wraps `return d` into
    /// `.some(d)`. Image types explicitly `return nil` when the
    /// parent server_id isn't set yet.

    private func gameToNewRow(_ g: Game) -> [String: JSONValue]? {
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
        // gameToNewRow never returns nil for a Game (only image rows do).
        var d = gameToNewRow(g)!
        d.removeValue(forKey: "client_id")
        d["server_id"] = .int(g.serverId!)
        d["last_synced_at"] = .string(Self.iso8601UTC(g.lastSyncedAt ?? Date(timeIntervalSince1970: 0)))
        return d
    }

    private func itemToNewRow(_ i: Item) -> [String: JSONValue]? {
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
        var d = itemToNewRow(i)!
        d.removeValue(forKey: "client_id")
        d["server_id"] = .int(i.serverId!)
        d["last_synced_at"] = .string(Self.iso8601UTC(i.lastSyncedAt ?? Date(timeIntervalSince1970: 0)))
        return d
    }

    private func completionToNewRow(_ c: GameCompletion) -> [String: JSONValue]? {
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
        var d = completionToNewRow(c)!
        d.removeValue(forKey: "client_id")
        d["server_id"] = .int(c.serverId!)
        d["last_synced_at"] = .string(Self.iso8601UTC(c.lastSyncedAt ?? Date(timeIntervalSince1970: 0)))
        return d
    }

    /// Image rows are only pushable once the parent's server_id is set —
    /// if the parent game/item is still `.localNew`, defer this row to
    /// the next sync. Returning `nil` here tells `bucket(...)` to skip.
    private func gameImageToNewRow(_ g: GameImage) -> [String: JSONValue]? {
        guard let gid = g.gameServerId else { return nil }
        return [
            "client_id":  .string(g.clientId.uuidString),
            "game_id":    .int(gid),
            "image_path": .string(g.imagePath),
        ]
    }

    private func itemImageToNewRow(_ i: ItemImage) -> [String: JSONValue]? {
        guard let iid = i.itemServerId else { return nil }
        return [
            "client_id":  .string(i.clientId.uuidString),
            "item_id":    .int(iid),
            "image_path": .string(i.imagePath),
        ]
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
