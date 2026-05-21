import Foundation
import SwiftData

@Model
final class Game {
    // Sync metadata
    @Attribute(.unique) var clientId: UUID
    var serverId: Int?
    var lastSyncedAt: Date?
    var syncStateRaw: String

    // Server columns
    var title: String
    var platform: String
    var genre: String?
    var gameDescription: String?    // 'description' is reserved on NSObject; the JSON key is still 'description'
    var series: String?
    var specialEdition: String?
    var conditionValue: String?     // 'condition' is fine as a property; renamed for readability
    var review: String?
    var starRating: Int?
    var metacriticRating: Int?
    var played: Int
    var pricePaid: Double?
    var pricechartingPrice: Double?
    var isPhysical: Int
    var digitalStore: String?
    var frontCoverImage: String?
    var backCoverImage: String?
    var releaseDate: Date?

    /// When the row was created on the phone (or first synced down). Used for
    /// stable ordering and tombstone cleanup.
    var createdAt: Date

    var syncState: SyncState {
        get {
            if let s = SyncState(rawValue: syncStateRaw) { return s }
            assertionFailure("Unknown syncStateRaw=\(syncStateRaw); falling back to .synced")
            return .synced
        }
        set { syncStateRaw = newValue.rawValue }
    }

    init(title: String, platform: String, syncState: SyncState = .localNew) {
        self.clientId = UUID()
        self.title = title
        self.platform = platform
        self.played = 0
        self.isPhysical = 1
        self.createdAt = Date()
        self.syncStateRaw = syncState.rawValue
    }
}
