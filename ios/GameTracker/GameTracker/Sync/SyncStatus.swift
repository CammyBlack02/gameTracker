import Foundation
import Observation

/// Observable status banner the UI binds to. Updated on the MainActor.
@Observable
@MainActor
final class SyncStatus {
    enum Phase: Equatable {
        case idle
        case syncing
        case error(String)
    }
    var phase: Phase = .idle
    var lastSyncedAt: Date?
    var pendingPushCount: Int = 0
    var conflictCount: Int = 0
}
