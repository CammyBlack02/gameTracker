import SwiftUI
import SwiftData

/// Modal list of every non-deleted Game in the local store, searchable
/// by title or platform. Returns the chosen game via `onPick`.
struct GamePickerSheet: View {
    let onPick: (Game) -> Void
    let imagesAPI: ImagesAPI

    @Environment(\.dismiss) private var dismiss

    @Query(
        filter: #Predicate<Game> { $0.syncStateRaw != "local_deleted" },
        sort: \Game.title,
        order: .forward
    ) private var allGames: [Game]

    @State private var search = ""

    private var filtered: [Game] {
        guard !search.isEmpty else { return allGames }
        let s = search.lowercased()
        return allGames.filter {
            $0.title.lowercased().contains(s) || $0.platform.lowercased().contains(s)
        }
    }

    var body: some View {
        NavigationStack {
            List(filtered) { game in
                Button {
                    onPick(game)
                    dismiss()
                } label: {
                    HStack(spacing: 12) {
                        CoverImage(gameServerId: game.serverId, face: .front, size: .thumb, api: imagesAPI)
                            .frame(width: 36, height: 54)
                            .clipShape(RoundedRectangle(cornerRadius: 4))
                        VStack(alignment: .leading, spacing: 2) {
                            Text(game.title)
                                .font(.body.weight(.medium))
                                .lineLimit(2)
                            Text(game.platform)
                                .font(.caption)
                                .foregroundStyle(.secondary)
                        }
                        Spacer()
                    }
                }
                .buttonStyle(.plain)
            }
            .listStyle(.plain)
            .searchable(text: $search, prompt: "Search title or platform")
            .navigationTitle("Pick a game")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { dismiss() }
                }
            }
            .themedBackground()
        }
    }
}
