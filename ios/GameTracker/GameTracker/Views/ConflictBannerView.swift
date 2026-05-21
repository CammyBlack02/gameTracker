import SwiftUI

/// Red banner shown at the top of relevant screens whenever the
/// SyncStatus reports `conflictCount > 0`. Tap to open the resolution list.
struct ConflictBannerView: View {
    @Bindable var status: SyncStatus
    let onTap: () -> Void

    var body: some View {
        if status.conflictCount > 0 {
            Button(action: onTap) {
                HStack {
                    Image(systemName: "exclamationmark.triangle.fill")
                    Text("\(status.conflictCount) sync conflict\(status.conflictCount == 1 ? "" : "s") — tap to resolve")
                    Spacer()
                    Image(systemName: "chevron.right").font(.caption)
                }
                .foregroundStyle(.white)
                .padding(.horizontal)
                .padding(.vertical, 10)
                .background(Color.red)
            }
            .buttonStyle(.plain)
        }
    }
}
