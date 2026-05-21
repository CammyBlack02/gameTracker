import SwiftUI

/// Thin always-on banner shown above the Library content while sync is
/// in flight or when the last sync failed. Mirrors ConflictBannerView's
/// placement (just below the nav bar).
struct SyncStatusBannerView: View {
    @Bindable var status: SyncStatus

    var body: some View {
        switch status.phase {
        case .idle:
            EmptyView()

        case .syncing:
            HStack(spacing: 8) {
                ProgressView().controlSize(.small)
                Text("Syncing…")
                    .font(.caption.weight(.medium))
                Spacer()
            }
            .foregroundStyle(.white)
            .padding(.horizontal)
            .padding(.vertical, 8)
            .background(Color.blue)

        case .error(let message):
            HStack(spacing: 8) {
                Image(systemName: "exclamationmark.triangle.fill")
                Text(message)
                    .font(.caption.weight(.medium))
                    .lineLimit(2)
                Spacer()
            }
            .foregroundStyle(.white)
            .padding(.horizontal)
            .padding(.vertical, 8)
            .background(Color.red)
        }
    }
}
