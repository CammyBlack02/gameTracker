import SwiftUI

/// Used for the 4 tabs whose implementation lives in Plan 3b.
struct PlaceholderTabView: View {
    let title: String
    let systemImage: String
    let blurb: String

    var body: some View {
        NavigationStack {
            ContentUnavailableView {
                Label(title, systemImage: systemImage)
            } description: {
                Text(blurb)
            }
            .navigationTitle(title)
        }
    }
}
