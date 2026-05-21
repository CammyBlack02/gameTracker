import SwiftUI

/// Horizontal scroller of extra-photo thumbnails. Tap → full-screen.
struct ExtrasGallery: View {
    let extras: [GameImage]
    let imagesAPI: ImagesAPI
    @State private var fullScreenExtra: GameImage?

    var body: some View {
        if !extras.isEmpty {
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(extras) { e in
                        Button { fullScreenExtra = e } label: {
                            ExtraThumb(image: e, imagesAPI: imagesAPI)
                        }
                        .buttonStyle(.plain)
                    }
                }
                .padding(.horizontal)
            }
            .frame(height: 100)
            .fullScreenCover(item: $fullScreenExtra) { extra in
                ExtraFullScreen(image: extra, imagesAPI: imagesAPI)
            }
        }
    }
}

private struct ExtraThumb: View {
    let image: GameImage
    let imagesAPI: ImagesAPI
    @State private var fileURL: URL?

    var body: some View {
        Group {
            if let url = fileURL, let img = UIImage(contentsOfFile: url.path) {
                Image(uiImage: img).resizable().aspectRatio(contentMode: .fill)
            } else {
                Rectangle().fill(.gray.opacity(0.2))
            }
        }
        .frame(width: 100, height: 100)
        .clipped()
        .clipShape(RoundedRectangle(cornerRadius: 6))
        .task(id: image.serverId) {
            guard let id = image.serverId else { return }
            fileURL = try? await imagesAPI.downloadExtra(imageServerId: id, type: .game, size: .thumb)
        }
    }
}

private struct ExtraFullScreen: View {
    let image: GameImage
    let imagesAPI: ImagesAPI
    @Environment(\.dismiss) private var dismiss
    @State private var fileURL: URL?

    var body: some View {
        ZStack {
            Color.black.ignoresSafeArea()
            if let url = fileURL, let img = UIImage(contentsOfFile: url.path) {
                Image(uiImage: img).resizable().aspectRatio(contentMode: .fit)
            } else {
                ProgressView().tint(.white)
            }
            VStack {
                HStack {
                    Spacer()
                    Button("Done") { dismiss() }.foregroundStyle(.white).padding()
                }
                Spacer()
            }
        }
        .task(id: image.serverId) {
            guard let id = image.serverId else { return }
            fileURL = try? await imagesAPI.downloadExtra(imageServerId: id, type: .game, size: .full)
        }
    }
}
