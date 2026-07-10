import Foundation
import SwiftData

@Model
final class Item {
    @Attribute(.unique) var clientId: UUID
    var serverId: Int?
    var lastSyncedAt: Date?
    var syncStateRaw: String
    /// See Game.serverVersionJSON — populated on conflict, cleared on
    /// resolve.
    var serverVersionJSON: String?

    var title: String
    var platform: String?
    var category: String           // "Systems" (console), "Controllers", "Game Accessories", or "Toys To Life". Older rows may carry the legacy "Console".
    var itemDescription: String?
    var conditionValue: String?
    var pricePaid: Double?
    var pricechartingPrice: Double?
    var frontImage: String?
    var backImage: String?
    var notes: String?
    var quantity: Int

    var createdAt: Date

    var syncState: SyncState {
        get {
            if let s = SyncState(rawValue: syncStateRaw) { return s }
            assertionFailure("Unknown syncStateRaw=\(syncStateRaw); falling back to .synced")
            return .synced
        }
        set { syncStateRaw = newValue.rawValue }
    }

    init(title: String, category: String, syncState: SyncState = .localNew) {
        self.clientId = UUID()
        self.title = title
        self.category = category
        self.quantity = 1
        self.createdAt = Date()
        self.syncStateRaw = syncState.rawValue
    }
}
