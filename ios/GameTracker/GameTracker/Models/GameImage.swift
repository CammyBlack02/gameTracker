import Foundation
import SwiftData

/// Extra photo attached to a game (the "extras" table on the server).
/// Phone only stores the metadata; the actual JPEG is fetched on demand
/// via `/api/v2/images/extra.php`.
@Model
final class GameImage {
    @Attribute(.unique) var clientId: UUID
    var serverId: Int?
    var lastSyncedAt: Date?
    var syncStateRaw: String

    var gameServerId: Int?
    /// Server's stored filename, e.g. "abc123.jpg". Used to build cache paths.
    var imagePath: String

    var createdAt: Date

    var syncState: SyncState {
        get { SyncState(rawValue: syncStateRaw) ?? .synced }
        set { syncStateRaw = newValue.rawValue }
    }

    init(imagePath: String, gameServerId: Int? = nil) {
        self.clientId = UUID()
        self.imagePath = imagePath
        self.gameServerId = gameServerId
        self.createdAt = Date()
        self.syncStateRaw = SyncState.localNew.rawValue
    }
}
