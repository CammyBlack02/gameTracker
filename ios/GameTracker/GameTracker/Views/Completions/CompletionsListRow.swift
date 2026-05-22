import SwiftUI

struct CompletionsListRow: View {
    let completion: GameCompletion
    let imagesAPI: ImagesAPI

    var body: some View {
        HStack(alignment: .top, spacing: 12) {
            CoverImage(gameServerId: completion.gameServerId,
                       face: .front,
                       size: .thumb,
                       api: imagesAPI)
                .frame(width: 40, height: 60)
                .clipShape(RoundedRectangle(cornerRadius: 4))

            VStack(alignment: .leading, spacing: 3) {
                Text(completion.title)
                    .font(.body.weight(.medium))
                    .lineLimit(2)

                HStack(spacing: 6) {
                    if let p = completion.platform, !p.isEmpty {
                        Text(p).font(.caption).foregroundStyle(.secondary)
                    }
                    if let d = completion.dateCompleted {
                        Text("·").font(.caption).foregroundStyle(.secondary)
                        Text(d.formatted(date: .abbreviated, time: .omitted))
                            .font(.caption).foregroundStyle(.secondary)
                    }
                    if let t = completion.timeTaken, !t.isEmpty {
                        Text("·").font(.caption).foregroundStyle(.secondary)
                        Text(t).font(.caption).foregroundStyle(.secondary)
                    }
                }

                if let n = completion.notes, !n.isEmpty {
                    Text(n)
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                        .lineLimit(2)
                }
            }

            Spacer()

            SyncStateBadge(state: completion.syncState)
        }
        .padding(.vertical, 4)
    }
}
