import SwiftUI

struct ItemsListRow: View {
    let item: Item
    let imagesAPI: ImagesAPI

    private var category: ItemCategory { ItemCategory(rawString: item.category) }

    var body: some View {
        HStack(spacing: 12) {
            CoverImage(itemServerId: item.serverId, face: .front, size: .thumb, api: imagesAPI)
                .frame(width: 40, height: 60)
                .clipShape(RoundedRectangle(cornerRadius: 4))

            VStack(alignment: .leading, spacing: 3) {
                Text(item.title)
                    .font(.body.weight(.medium))
                    .lineLimit(2)

                HStack(spacing: 6) {
                    Image(systemName: category.systemImage)
                        .font(.caption)
                        .foregroundStyle(.secondary)
                    if let p = item.platform, !p.isEmpty {
                        Text(p).font(.caption).foregroundStyle(.secondary)
                    }
                    if let c = item.conditionValue, !c.isEmpty {
                        if item.platform?.isEmpty == false {
                            Text("·").font(.caption).foregroundStyle(.secondary)
                        }
                        Text(c).font(.caption).foregroundStyle(.secondary)
                    }
                }
            }

            Spacer()

            if item.quantity > 1 {
                Text("×\(item.quantity)")
                    .font(.caption2.monospacedDigit().weight(.medium))
                    .padding(.horizontal, 6)
                    .padding(.vertical, 2)
                    .background(Color.gray.opacity(0.15), in: Capsule())
                    .foregroundStyle(.secondary)
            }

            SyncStateBadge(state: item.syncState)
        }
        .padding(.vertical, 4)
    }
}
