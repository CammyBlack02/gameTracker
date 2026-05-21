import SwiftUI

/// Single row in the list view: small cover + title + platform + status badge.
struct GameListRow: View {
    let game: Game
    let imagesAPI: ImagesAPI

    var body: some View {
        HStack(spacing: 12) {
            CoverImage(gameServerId: game.serverId, face: .front, size: .thumb, api: imagesAPI)
                .frame(width: 40, height: 60)
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

            SyncStateBadge(state: game.syncState)
        }
        .padding(.vertical, 4)
    }
}

/// Tiny status pill, reused from DebugHomeView's visual vocabulary.
struct SyncStateBadge: View {
    let state: SyncState
    var body: some View {
        switch state {
        case .synced:        EmptyView()
        case .localNew:      Text("new").font(.caption2).foregroundStyle(.blue)
        case .localModified: Text("edit").font(.caption2).foregroundStyle(.orange)
        case .localDeleted:  Text("del").font(.caption2).foregroundStyle(.red)
        case .conflict:      Image(systemName: "exclamationmark.triangle.fill")
                                .font(.caption).foregroundStyle(.red)
        }
    }
}
