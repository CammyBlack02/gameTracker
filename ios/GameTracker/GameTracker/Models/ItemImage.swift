import Foundation
import SwiftData

@Model
final class ItemImage {
    @Attribute(.unique) var clientId: UUID
    var serverId: Int?
    var lastSyncedAt: Date?
    var syncStateRaw: String

    var itemServerId: Int?
    var imagePath: String

    var createdAt: Date

    var syncState: SyncState {
        get { SyncState(rawValue: syncStateRaw) ?? .synced }
        set { syncStateRaw = newValue.rawValue }
    }

    init(imagePath: String, itemServerId: Int? = nil) {
        self.clientId = UUID()
        self.imagePath = imagePath
        self.itemServerId = itemServerId
        self.createdAt = Date()
        self.syncStateRaw = SyncState.localNew.rawValue
    }
}
