import Foundation

/// State of a local row with respect to the server.
/// Stored as a raw `String` so SwiftData persists it as TEXT.
enum SyncState: String, Codable, CaseIterable {
    /// Row matches the server's last-seen version exactly.
    case synced
    /// Row was edited locally since last sync; pending push.
    case localModified = "local_modified"
    /// Row was created on phone; has no `serverId` yet.
    case localNew = "local_new"
    /// Row was deleted locally; pending delete-push.
    /// (We keep a tombstone row rather than actually deleting so we
    /// can communicate the deletion to the server on next sync.)
    case localDeleted = "local_deleted"
    /// Push response said this row conflicts; awaiting user resolution.
    case conflict
}
