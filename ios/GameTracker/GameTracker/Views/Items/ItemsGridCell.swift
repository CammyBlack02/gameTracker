import SwiftUI

struct ItemsGridCell: View {
    let item: Item
    let imagesAPI: ImagesAPI

    var body: some View {
        ZStack(alignment: .bottomLeading) {
            CoverImage(itemServerId: item.serverId, face: .front, size: .thumb, api: imagesAPI)
                .frame(maxWidth: .infinity, maxHeight: .infinity)
                .clipShape(RoundedRectangle(cornerRadius: 6))

            // Bottom gradient + title overlay
            LinearGradient(colors: [.black.opacity(0.75), .clear],
                           startPoint: .bottom, endPoint: .center)
                .clipShape(RoundedRectangle(cornerRadius: 6))

            Text(item.title)
                .font(.caption2.weight(.semibold))
                .foregroundStyle(.white)
                .lineLimit(2)
                .padding(.horizontal, 6)
                .padding(.bottom, 4)

            // Top-left sync badge + top-right quantity badge
            VStack {
                HStack {
                    SyncStateBadge(state: item.syncState)
                        .padding(4)
                        .background(Color.black.opacity(0.4), in: Capsule())
                    Spacer()
                    if item.quantity > 1 {
                        Text("×\(item.quantity)")
                            .font(.caption2.monospacedDigit().weight(.semibold))
                            .foregroundStyle(.white)
                            .padding(.horizontal, 6)
                            .padding(.vertical, 2)
                            .background(Color.black.opacity(0.55), in: Capsule())
                    }
                }
                Spacer()
            }
            .padding(4)
        }
    }
}
