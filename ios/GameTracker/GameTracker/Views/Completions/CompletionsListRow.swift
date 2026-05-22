import SwiftUI

struct CompletionsListRow: View {
    let completion: GameCompletion
    let imagesAPI: ImagesAPI

    private var dateLabel: String? {
        let fmt: (Date) -> String = { $0.formatted(date: .abbreviated, time: .omitted) }
        switch (completion.dateStarted, completion.dateCompleted) {
        case let (s?, c?): return "\(fmt(s)) → \(fmt(c))"
        case (nil, let c?): return fmt(c)
        case (let s?, nil): return "Started \(fmt(s))"
        case (nil, nil): return nil
        }
    }

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
                    if let dateLabel = dateLabel {
                        Text("·").font(.caption).foregroundStyle(.secondary)
                        Text(dateLabel).font(.caption).foregroundStyle(.secondary)
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
