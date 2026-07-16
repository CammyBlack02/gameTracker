import Foundation
import SwiftData

/// Orchestrates a single sync cycle:
///   1. GET /sync/changes?since=<lastSyncedAt>
///   2. ChangeApplier applies them
///   3. PushBuilder gathers pending local rows
///   4. POST /sync/push
///   5. Apply push results (accepted -> mark synced + stamp server_id; conflict -> mark conflict)
///   6. Save context, persist new lastSyncedAt
///
/// All state-mutating work happens on the SwiftData context; SyncEngine
/// itself is `MainActor` because it touches the @Observable SyncStatus.
@MainActor
final class SyncEngine {

    let context: ModelContext
    let syncAPI: SyncAPI
    let status: SyncStatus
    let imagesAPI: ImagesAPI?

    /// Tracks whether sync has been attempted at all in this process
    /// lifetime. Used by `runOnceIfNeeded` so that the per-tab
    /// `.task` modifiers fire a sync only once per app launch instead
    /// of on every tab switch / foreground return.
    private var hasSyncedThisSession = false

    init(context: ModelContext, syncAPI: SyncAPI, status: SyncStatus, imagesAPI: ImagesAPI? = nil) {
        self.context = context
        self.syncAPI = syncAPI
        self.status = status
        self.imagesAPI = imagesAPI
    }

    /// Sync once per app launch. Calls `runOnce` on the first
    /// invocation and is a no-op thereafter (including after a
    /// manual sync from Settings, which also flips the flag). Used
    /// by tab `.task` modifiers so that simply switching tabs no
    /// longer kicks off another sync.
    func runOnceIfNeeded() async throws {
        guard !hasSyncedThisSession else { return }
        hasSyncedThisSession = true
        try await runOnce()
    }

    /// Run a complete sync cycle. Always runs regardless of the
    /// session flag — used by the manual "Sync now" button.
    func runOnce() async throws {
        hasSyncedThisSession = true
        status.phase = .syncing
        do {
            // 1+2: pull
            let meta = fetchOrCreateMeta()
            let changes = try await syncAPI.fetchChanges(since: meta.lastSyncedAt)
            ChangeApplier(context: context, imagesAPI: imagesAPI).apply(changes)
            meta.lastSyncedAt = changes.serverNow

            // 3+4: push
            let payload = try PushBuilder(context: context).build()
            let response = try await syncAPI.push(payload)

            // 5: reconcile push results
            try applyPushResults(response)

            try context.save()

            status.lastSyncedAt = changes.serverNow
            status.pendingPushCount = try countPending()
            status.conflictCount = try countConflicts()
            status.phase = .idle
        } catch {
            // Classify "the request never reached / never came back
            // from the server" as the soft `.offline` state — the
            // self-hosted backend going down briefly is an everyday
            // occurrence, not an emergency. URLSession surfaces these
            // as URLError, but APIClient wraps them in
            // `APIError.transport(URLError)` before they reach us.
            // Catch both forms so airplane-mode → no red banner.
            if Self.isTransportError(error) {
                status.phase = .offline
            } else {
                status.phase = .error(error.localizedDescription)
            }
            throw error
        }
    }

    private static func isTransportError(_ error: Error) -> Bool {
        if error is URLError { return true }
        if case APIError.transport = error { return true }
        return false
    }

    // MARK: - Response application

    private func applyPushResults(_ resp: PushResponseDTO) throws {
        try applyResults(resp.games,           of: Game.self)
        try applyResults(resp.items,           of: Item.self)
        try applyResults(resp.gameCompletions, of: GameCompletion.self)
        try applyResults(resp.gameImages,      of: GameImage.self)
        try applyResults(resp.itemImages,      of: ItemImage.self)
    }

    /// Applies a push-response bucket for one table. Fable §4 (last item):
    /// the five per-type `applyXResult` functions collapsed here.
    ///
    /// Implementation note: `#Predicate` does not reliably specialize
    /// over a generic type parameter, so we can't run the per-row
    /// `WHERE clientId = ?` / `WHERE serverId = ?` fetches inside a
    /// generic function. Instead we batch-fetch all rows of type T once
    /// per bucket, index by clientId and serverId, and do dictionary
    /// lookups per result. A typical push response has a handful of
    /// results per table, so one O(n) fetch + O(k) lookups is at least
    /// as fast as k × per-row indexed fetches.
    private func applyResults<T: SyncableModel>(
        _ results: [PushRowResultDTO],
        of _: T.Type
    ) throws {
        guard !results.isEmpty else { return }
        let rows = try context.fetch(FetchDescriptor<T>())
        let byClient: [UUID: T] = Dictionary(
            rows.map { ($0.clientId, $0) },
            uniquingKeysWith: { first, _ in first }
        )
        let byServer: [Int: T] = Dictionary(
            rows.compactMap { row in row.serverId.map { ($0, row) } },
            uniquingKeysWith: { first, _ in first }
        )
        for r in results {
            let row: T? = {
                if let cid = r.clientId, let uuid = UUID(uuidString: cid) {
                    return byClient[uuid]
                }
                if let sid = r.serverId {
                    return byServer[sid]
                }
                return nil
            }()
            guard let row else { continue }
            applyOutcome(r, to: row)
        }
    }

    private func applyOutcome<T: SyncableModel>(_ r: PushRowResultDTO, to row: T) {
        // If the row was tombstoned locally (we asked the server to delete
        // it), an "accepted" or "not_found" result means the server-side
        // deletion succeeded — hard-delete the local row instead of
        // remarking it .synced (which would resurrect it). Fable §4 Bug 1.
        let wasLocallyDeleted = row.syncStateRaw == SyncState.localDeleted.rawValue

        switch r.result {
        case "accepted":
            if wasLocallyDeleted {
                context.delete(row)
                return
            }
            if let sid = r.serverId { row.serverId = sid }
            row.lastSyncedAt = r.updatedAt.flatMap(Self.parseISO) ?? Date()
            row.syncStateRaw = SyncState.synced.rawValue
            row.serverVersionJSON = nil
        case "conflict":
            row.syncStateRaw = SyncState.conflict.rawValue
            // Stash the server's version so ConflictDetailView can show
            // it and "Keep server" can apply it. Fable §4 Bug 2 + 3.
            if let sv = r.serverVersion {
                row.serverVersionJSON = Self.encodeServerVersion(sv.raw)
            }
        case "not_found":
            // Applies whether the row was tombstoned or not — if the
            // server says the row doesn't exist, the local row is stale.
            context.delete(row)
        case "rejected":
            break
        default:
            break
        }
    }

    /// Serialise the server_version blob for storage on the local row.
    /// The dict was decoded from the push response as [String: JSONValue];
    /// we re-encode it verbatim (as a JSON string) so ConflictDetailView
    /// can round-trip it into a typed DTO at resolve time.
    private static func encodeServerVersion(_ raw: [String: JSONValue]) -> String? {
        guard let data = try? JSONEncoder().encode(raw),
              let str = String(data: data, encoding: .utf8) else {
            return nil
        }
        return str
    }

    // MARK: - Metadata + counts

    private func fetchOrCreateMeta() -> SyncMetadata {
        if let existing = try? context.fetch(FetchDescriptor<SyncMetadata>()).first {
            return existing
        }
        let m = SyncMetadata()
        context.insert(m)
        return m
    }

    private func countPending() throws -> Int {
        let gms = try context.fetch(FetchDescriptor<Game>())
            .filter { $0.syncState == .localNew || $0.syncState == .localModified || $0.syncState == .localDeleted }
        let itms = try context.fetch(FetchDescriptor<Item>())
            .filter { $0.syncState == .localNew || $0.syncState == .localModified || $0.syncState == .localDeleted }
        let cmps = try context.fetch(FetchDescriptor<GameCompletion>())
            .filter { $0.syncState == .localNew || $0.syncState == .localModified || $0.syncState == .localDeleted }
        return gms.count + itms.count + cmps.count
    }

    private func countConflicts() throws -> Int {
        let gms = try context.fetch(FetchDescriptor<Game>()).filter { $0.syncState == .conflict }
        let itms = try context.fetch(FetchDescriptor<Item>()).filter { $0.syncState == .conflict }
        return gms.count + itms.count
    }

    private static func parseISO(_ s: String) -> Date? {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withInternetDateTime]
        return f.date(from: s)
    }
}

/// Tiny protocol implemented by every `@Model` in the app that carries
/// sync metadata. Lets SyncEngine's response applier be generic over
/// the model class without using `#Predicate` (which doesn't reliably
/// specialize over generic types).
protocol SyncableModel: PersistentModel {
    var clientId: UUID { get set }
    var serverId: Int? { get set }
    var lastSyncedAt: Date? { get set }
    var syncStateRaw: String { get set }
    var serverVersionJSON: String? { get set }
}

extension Game: SyncableModel {}
extension Item: SyncableModel {}
extension GameCompletion: SyncableModel {}
extension GameImage: SyncableModel {}
extension ItemImage: SyncableModel {}
