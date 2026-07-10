import Foundation
import SwiftData

/// Applies a `ChangesResponseDTO` to the local SwiftData store. The
/// rules are intentionally conservative: never overwrite a local pending
/// edit (those are reconciled via the push step's conflict detection).
struct ChangeApplier {
    let context: ModelContext

    func apply(_ response: ChangesResponseDTO) {
        for dto in response.games           { applyGame(dto) }
        for dto in response.items           { applyItem(dto) }
        for dto in response.gameCompletions { applyCompletion(dto) }
        for dto in response.gameImages      { applyGameImage(dto) }
        for dto in response.itemImages      { applyItemImage(dto) }
        for d in response.deletions         { applyDeletion(d) }
    }

    // MARK: - Per-table appliers

    private func applyGame(_ dto: GameDTO) {
        let existing = fetchGame(serverId: dto.id)
        if let g = existing {
            // Don't clobber local edits.
            guard g.syncState == .synced else { return }
            copy(dto, into: g)
            g.syncState = .synced
            g.lastSyncedAt = parseDate(dto.updatedAt)
        } else {
            let g = Game(title: dto.title, platform: dto.platform, syncState: .synced)
            g.serverId = dto.id
            copy(dto, into: g)
            g.lastSyncedAt = parseDate(dto.updatedAt)
            context.insert(g)
        }
    }

    private func applyItem(_ dto: ItemDTO) {
        let existing = fetchItem(serverId: dto.id)
        if let i = existing {
            guard i.syncState == .synced else { return }
            copy(dto, into: i)
            i.syncState = .synced
            i.lastSyncedAt = parseDate(dto.updatedAt)
        } else {
            let i = Item(title: dto.title, category: dto.category, syncState: .synced)
            i.serverId = dto.id
            copy(dto, into: i)
            i.lastSyncedAt = parseDate(dto.updatedAt)
            context.insert(i)
        }
    }

    private func applyCompletion(_ dto: GameCompletionDTO) {
        let existing = fetchCompletion(serverId: dto.id)
        if let c = existing {
            guard c.syncState == .synced else { return }
            copy(dto, into: c)
            c.syncState = .synced
            c.lastSyncedAt = parseDate(dto.updatedAt)
        } else {
            let c = GameCompletion(title: dto.title, syncState: .synced)
            c.serverId = dto.id
            copy(dto, into: c)
            c.lastSyncedAt = parseDate(dto.updatedAt)
            context.insert(c)
        }
    }

    private func applyGameImage(_ dto: GameImageDTO) {
        let existing = fetchGameImage(serverId: dto.id)
        if let g = existing {
            guard g.syncState == .synced else { return }
            g.imagePath = dto.imagePath
            g.gameServerId = dto.gameId
            g.lastSyncedAt = parseDate(dto.updatedAt)
        } else {
            let g = GameImage(imagePath: dto.imagePath, gameServerId: dto.gameId)
            g.serverId = dto.id
            g.syncState = .synced
            g.lastSyncedAt = parseDate(dto.updatedAt)
            context.insert(g)
        }
    }

    private func applyItemImage(_ dto: ItemImageDTO) {
        let existing = fetchItemImage(serverId: dto.id)
        if let i = existing {
            guard i.syncState == .synced else { return }
            i.imagePath = dto.imagePath
            i.itemServerId = dto.itemId
            i.lastSyncedAt = parseDate(dto.updatedAt)
        } else {
            let i = ItemImage(imagePath: dto.imagePath, itemServerId: dto.itemId)
            i.serverId = dto.id
            i.syncState = .synced
            i.lastSyncedAt = parseDate(dto.updatedAt)
            context.insert(i)
        }
    }

    private func applyDeletion(_ d: DeletionDTO) {
        switch d.tableName {
        case "games":
            if let g = fetchGame(serverId: d.serverId), g.syncState == .synced {
                context.delete(g)
            }
        case "items":
            if let i = fetchItem(serverId: d.serverId), i.syncState == .synced {
                context.delete(i)
            }
        case "game_completions":
            if let c = fetchCompletion(serverId: d.serverId), c.syncState == .synced {
                context.delete(c)
            }
        case "game_images":
            if let g = fetchGameImage(serverId: d.serverId), g.syncState == .synced {
                context.delete(g)
            }
        case "item_images":
            if let i = fetchItemImage(serverId: d.serverId), i.syncState == .synced {
                context.delete(i)
            }
        default:
            break
        }
    }

    // MARK: - Field copiers

    private func copy(_ dto: GameDTO, into g: Game) {
        g.title = dto.title
        g.platform = dto.platform
        g.genre = dto.genre
        g.gameDescription = dto.description
        g.series = dto.series
        g.specialEdition = dto.specialEdition
        g.conditionValue = dto.condition
        g.review = dto.review
        g.starRating = dto.starRating
        g.metacriticRating = dto.metacriticRating
        g.played = dto.played ?? 0
        g.pricePaid = dto.pricePaid
        g.pricechartingPrice = dto.pricechartingPrice
        g.isPhysical = dto.isPhysical ?? 1
        g.digitalStore = dto.digitalStore
        g.frontCoverImage = dto.frontCoverImage
        g.backCoverImage = dto.backCoverImage
        g.releaseDate = dto.releaseDate.flatMap(Self.parseYMD)
        // Use the server's created_at so "Recently added" sorts by
        // when the user actually added the game, not by when this
        // device first pulled it down. The Game initializer sets
        // createdAt = Date() (a local stamp) which is right for
        // brand-new local rows but wrong for everything synced.
        if let raw = dto.createdAt, let date = parseDate(raw) {
            g.createdAt = date
        }
    }

    private func copy(_ dto: ItemDTO, into i: Item) {
        i.title = dto.title
        i.platform = dto.platform
        i.category = dto.category
        i.itemDescription = dto.description
        i.conditionValue = dto.condition
        i.pricePaid = dto.pricePaid
        i.pricechartingPrice = dto.pricechartingPrice
        i.frontImage = dto.frontImage
        i.backImage = dto.backImage
        i.notes = dto.notes
        i.quantity = dto.quantity ?? 1
        if let raw = dto.createdAt, let date = parseDate(raw) {
            i.createdAt = date
        }
    }

    private func copy(_ dto: GameCompletionDTO, into c: GameCompletion) {
        c.gameServerId = dto.gameId
        c.title = dto.title
        c.platform = dto.platform
        c.timeTaken = dto.timeTaken
        c.dateStarted = dto.dateStarted.flatMap(Self.parseYMD)
        c.dateCompleted = dto.dateCompleted.flatMap(Self.parseYMD)
        c.completionYear = dto.completionYear
        c.notes = dto.notes
    }

    // MARK: - Conflict resolution ("Keep server version")

    /// Applies the raw JSON blob stashed on `game.serverVersionJSON` (put
    /// there by SyncEngine when the last push returned a conflict) to
    /// the local Game row. Called from ConflictDetailView. Clears the
    /// conflict marker and stamps `lastSyncedAt` from the server row's
    /// updated_at. See Phase 3a plan (Fable §4 Bug 2 + Bug 3).
    func applyStoredServerVersion(to game: Game) {
        guard let json = game.serverVersionJSON,
              let data = json.data(using: .utf8) else {
            // No stashed server version — nothing to apply. Clear the
            // conflict marker anyway so the row isn't stuck; without a
            // stored version we can't do better than that.
            game.syncStateRaw = SyncState.synced.rawValue
            return
        }
        guard let dto = try? JSONDecoder().decode(GameDTO.self, from: data) else {
            // Malformed blob — same fallback.
            game.syncStateRaw = SyncState.synced.rawValue
            game.serverVersionJSON = nil
            return
        }
        copy(dto, into: game)
        game.serverId = dto.id
        game.lastSyncedAt = parseDate(dto.updatedAt) ?? Date()
        game.syncStateRaw = SyncState.synced.rawValue
        game.serverVersionJSON = nil
    }

    /// Item counterpart to `applyStoredServerVersion(to: Game)`.
    func applyStoredServerVersion(to item: Item) {
        guard let json = item.serverVersionJSON,
              let data = json.data(using: .utf8) else {
            item.syncStateRaw = SyncState.synced.rawValue
            return
        }
        guard let dto = try? JSONDecoder().decode(ItemDTO.self, from: data) else {
            item.syncStateRaw = SyncState.synced.rawValue
            item.serverVersionJSON = nil
            return
        }
        copy(dto, into: item)
        item.serverId = dto.id
        item.lastSyncedAt = parseDate(dto.updatedAt) ?? Date()
        item.syncStateRaw = SyncState.synced.rawValue
        item.serverVersionJSON = nil
    }

    // MARK: - Lookup helpers

    private func fetchGame(serverId: Int) -> Game? {
        let sid = serverId
        let p = #Predicate<Game> { $0.serverId == sid }
        return try? context.fetch(FetchDescriptor(predicate: p)).first
    }
    private func fetchItem(serverId: Int) -> Item? {
        let sid = serverId
        let p = #Predicate<Item> { $0.serverId == sid }
        return try? context.fetch(FetchDescriptor(predicate: p)).first
    }
    private func fetchCompletion(serverId: Int) -> GameCompletion? {
        let sid = serverId
        let p = #Predicate<GameCompletion> { $0.serverId == sid }
        return try? context.fetch(FetchDescriptor(predicate: p)).first
    }
    private func fetchGameImage(serverId: Int) -> GameImage? {
        let sid = serverId
        let p = #Predicate<GameImage> { $0.serverId == sid }
        return try? context.fetch(FetchDescriptor(predicate: p)).first
    }
    private func fetchItemImage(serverId: Int) -> ItemImage? {
        let sid = serverId
        let p = #Predicate<ItemImage> { $0.serverId == sid }
        return try? context.fetch(FetchDescriptor(predicate: p)).first
    }

    // MARK: - Date parsing

    private func parseDate(_ s: String) -> Date? {
        let fmts = [
            "yyyy-MM-dd'T'HH:mm:ss'Z'",
            "yyyy-MM-dd'T'HH:mm:ssXXXXX",
            "yyyy-MM-dd HH:mm:ss",
        ]
        for fmt in fmts {
            let f = DateFormatter()
            f.locale = Locale(identifier: "en_US_POSIX")
            f.timeZone = TimeZone(identifier: "UTC")
            f.dateFormat = fmt
            if let d = f.date(from: s) { return d }
        }
        return nil
    }

    private static func parseYMD(_ s: String) -> Date? {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.timeZone = TimeZone(identifier: "UTC")
        f.dateFormat = "yyyy-MM-dd"
        return f.date(from: s)
    }
}
