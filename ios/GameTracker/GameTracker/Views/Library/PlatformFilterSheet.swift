import SwiftUI
import SwiftData

struct PlatformFilterSheet: View {
    @Binding var selected: Set<String>
    @Environment(\.dismiss) private var dismiss
    @Query(sort: \Game.platform) private var allGames: [Game]

    private var platforms: [String] {
        let unique = Set(allGames.map(\.platform))
        return unique.sorted()
    }

    var body: some View {
        NavigationStack {
            List {
                ForEach(platforms, id: \.self) { p in
                    PlatformRow(platform: p, selected: $selected)
                }
            }
            .navigationTitle("Platforms")
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Clear") { selected.removeAll() }
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") { dismiss() }
                }
            }
            .themedBackground()
        }
    }
}

private struct PlatformRow: View {
    let platform: String
    @Binding var selected: Set<String>

    var body: some View {
        Button {
            if selected.contains(platform) {
                selected.remove(platform)
            } else {
                selected.insert(platform)
            }
        } label: {
            HStack {
                Text(platform)
                Spacer()
                if selected.contains(platform) {
                    Image(systemName: "checkmark").foregroundStyle(.tint)
                }
            }
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
    }
}
