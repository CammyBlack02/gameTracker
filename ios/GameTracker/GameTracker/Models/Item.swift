import Foundation
import SwiftData

@Model
final class Item {
    @Attribute(.unique) var clientId: UUID
    var serverId: Int?
    var lastSyncedAt: Date?
    var syncStateRaw: String

    var title: String
    var platform: String?
    var category: String           // "console" or "accessory"
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
        get { SyncState(rawValue: syncStateRaw) ?? .synced }
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
