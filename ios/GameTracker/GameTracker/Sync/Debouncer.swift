import Foundation

/// Collapses rapid `fire()` calls into a single deferred run.
/// Each `fire()` cancels the pending task (if any) and schedules a new
/// one `delay` seconds later. Safe to share across actors.
actor Debouncer {

    private let delay: TimeInterval
    private let action: @Sendable () async -> Void
    private var pending: Task<Void, Never>?

    init(delay: TimeInterval, action: @escaping @Sendable () async -> Void) {
        self.delay = delay
        self.action = action
    }

    func fire() {
        pending?.cancel()
        let captured = action
        let d = delay
        pending = Task { [weak self] in
            try? await Task.sleep(nanoseconds: UInt64(d * 1_000_000_000))
            guard !Task.isCancelled else { return }
            await captured()
            await self?.clearPending()
        }
    }

    /// Cancel any pending run.
    func cancel() {
        pending?.cancel()
        pending = nil
    }

    private func clearPending() { pending = nil }
}
