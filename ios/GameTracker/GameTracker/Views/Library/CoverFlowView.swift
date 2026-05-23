import SwiftUI
import SwiftData

struct CoverFlowView: View {
    let games: [Game]
    let imagesAPI: ImagesAPI
    let onSelectGame: (PersistentIdentifier) -> Void

    @Environment(\.theme) private var theme
    @State private var focusedIndex: Int = 0

    private var focused: Game? {
        guard focusedIndex >= 0, focusedIndex < games.count else { return nil }
        return games[focusedIndex]
    }

    var body: some View {
        VStack(spacing: 12) {
            CoverFlowSceneView(games: games,
                                imagesAPI: imagesAPI,
                                theme: theme,
                                focusedIndex: $focusedIndex,
                                onActivateFocused: activateFocused)
                .frame(maxWidth: .infinity)
                .frame(minHeight: 360)

            VStack(spacing: 2) {
                Text(focused?.title ?? "")
                    .font(.title3.bold())
                    .lineLimit(1)
                Text(focused?.platform ?? "")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
            }
            .padding(.horizontal, 16)
            .padding(.bottom, 12)
        }
        .onChange(of: games.count) { _, _ in
            // If the filter shrunk the list, ensure focus stays in range.
            if focusedIndex >= games.count {
                focusedIndex = max(0, games.count - 1)
            }
        }
    }

    private func activateFocused() {
        guard let game = focused else { return }
        onSelectGame(game.persistentModelID)
    }
}
