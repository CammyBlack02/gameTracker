import SwiftUI

/// One game in the grid: full-cover with a tiny status overlay.
struct GameGridCell: View {
    let game: Game
    let imagesAPI: ImagesAPI

    var body: some View {
        CoverImage(gameServerId: game.serverId, face: .front, size: .thumb, api: imagesAPI)
            .clipShape(RoundedRectangle(cornerRadius: 6))
            .overlay(alignment: .topTrailing) {
                if game.syncState != .synced {
                    SyncStateBadge(state: game.syncState)
                        .padding(4)
                        .background(.ultraThinMaterial, in: Capsule())
                        .padding(4)
                }
            }
            .overlay(alignment: .bottom) {
                Text(game.title)
                    .font(.caption2.weight(.medium))
                    .foregroundStyle(.white)
                    .lineLimit(2)
                    .multilineTextAlignment(.center)
                    .padding(.horizontal, 4)
                    .padding(.vertical, 2)
                    .frame(maxWidth: .infinity)
                    .background(
                        LinearGradient(colors: [.black.opacity(0), .black.opacity(0.7)],
                                       startPoint: .top, endPoint: .bottom)
                    )
            }
    }
}
