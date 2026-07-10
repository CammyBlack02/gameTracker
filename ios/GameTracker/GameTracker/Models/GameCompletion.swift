import Foundation
import SwiftData

@Model
final class GameCompletion {
    @Attribute(.unique) var clientId: UUID
    var serverId: Int?
    var lastSyncedAt: Date?
    var syncStateRaw: String
    /// See Game.serverVersionJSON.
    var serverVersionJSON: String?

    /// Server-side foreign key to `games.id`. May be `nil` if the parent
    /// game is itself still `localNew` and unpushed.
    var gameServerId: Int?

    var title: String
    var platform: String?
    var timeTaken: String?
    var dateStarted: Date?
    var dateCompleted: Date?
    var completionYear: Int?
    var notes: String?

    var createdAt: Date

    var syncState: SyncState {
        get {
            if let s = SyncState(rawValue: syncStateRaw) { return s }
            assertionFailure("Unknown syncStateRaw=\(syncStateRaw); falling back to .synced")
            return .synced
        }
        set { syncStateRaw = newValue.rawValue }
    }

    init(title: String, syncState: SyncState = .localNew) {
        self.clientId = UUID()
        self.title = title
        self.createdAt = Date()
        self.syncStateRaw = syncState.rawValue
    }
}
