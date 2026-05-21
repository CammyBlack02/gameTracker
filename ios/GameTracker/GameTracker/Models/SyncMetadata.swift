import Foundation
import SwiftData

@Model
final class SyncMetadata {
    /// Server's `server_now` from the last successful `/sync/changes` response.
    /// Used as the `since` parameter on the next call. `nil` means full pull.
    var lastSyncedAt: Date?

    /// ID of the logged-in user (returned by `/auth/token`). Stored so a
    /// stale local DB belonging to a different user can be wiped on login.
    var userId: Int?

    init(lastSyncedAt: Date? = nil, userId: Int? = nil) {
        self.lastSyncedAt = lastSyncedAt
        self.userId = userId
    }
}
