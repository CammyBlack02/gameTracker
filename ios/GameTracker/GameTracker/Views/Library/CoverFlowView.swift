import SwiftUI
import SwiftData

struct CoverFlowView: View {
    let games: [Game]
    let imagesAPI: ImagesAPI
    let onSelectGame: (PersistentIdentifier) -> Void

    @State private var focusedIndex: Int = 0
    @State private var showingBack: Bool = false

    private var focused: Game? {
        guard focusedIndex >= 0, focusedIndex < games.count else { return nil }
        return games[focusedIndex]
    }

    var body: some View {
        VStack(spacing: 12) {
            CoverFlowSceneView(games: games,
                                imagesAPI: imagesAPI,
                                focusedIndex: $focusedIndex,
                                showingBack: $showingBack,
                                onActivateFocused: activateFocused)
                .frame(maxWidth: .infinity)
                .frame(minHeight: 360)

            HStack(alignment: .firstTextBaseline, spacing: 12) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(focused?.title ?? "")
                        .font(.title3.bold())
                        .lineLimit(1)
                    Text(focused?.platform ?? "")
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                        .lineLimit(1)
                }
                Spacer()
                Button {
                    showingBack.toggle()
                } label: {
                    Image(systemName: showingBack
                                      ? "arrow.uturn.backward.circle.fill"
                                      : "arrow.trianglehead.2.clockwise.rotate.90.circle")
                        .font(.title2)
                        .accessibilityLabel(showingBack ? "Show front cover" : "Show back cover")
                }
                .disabled(focused == nil)
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
        .onChange(of: focusedIndex) { _, _ in
            // The scene already drops focus-flip state when focus moves;
            // keep the SwiftUI binding in sync so the icon flips back.
            showingBack = false
        }
    }

    private func activateFocused() {
        guard let game = focused else { return }
        onSelectGame(game.persistentModelID)
    }
}
