import Foundation

/// Owns a `Debouncer` configured for sync. Views call `pingAfterMutation()`
/// from their save paths; multiple rapid saves coalesce into one
/// `SyncEngine.runOnce()` call ~5 s after the last save.
///
/// Errors thrown by `runOnce()` are swallowed (logged via error_log) — a
/// failed background sync will be retried on the next mutation or the
/// next foreground / pull-to-refresh event.
@MainActor
final class SyncTrigger {

    private let engine: SyncEngine
    private let debouncer: Debouncer

    init(engine: SyncEngine, delay: TimeInterval = 5.0) {
        self.engine = engine
        let captured = engine
        self.debouncer = Debouncer(delay: delay) { [captured] in
            try? await captured.runOnce()
        }
    }

    /// Schedule a sync `delay` seconds from now. Repeated calls collapse.
    func pingAfterMutation() {
        Task { await debouncer.fire() }
    }

    /// Cancel any pending background sync (used when the app is about to
    /// foreground-sync explicitly via pull-to-refresh).
    func cancelPending() {
        Task { await debouncer.cancel() }
    }
}
