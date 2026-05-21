import SwiftUI

/// Async-loaded cover image with a placeholder. Uses `ImagesAPI`'s
/// on-disk cache, so subsequent renders for the same (gameServerId,
/// face, size) are instant.
struct CoverImage: View {

    let gameServerId: Int?
    let face: ImagesAPI.Face
    let size: ImagesAPI.Size
    let api: ImagesAPI

    @State private var localURL: URL?
    @State private var failed = false

    init(gameServerId: Int?,
         face: ImagesAPI.Face = .front,
         size: ImagesAPI.Size = .thumb,
         api: ImagesAPI) {
        self.gameServerId = gameServerId
        self.face = face
        self.size = size
        self.api = api
    }

    var body: some View {
        Group {
            if let url = localURL, let img = UIImage(contentsOfFile: url.path) {
                Image(uiImage: img)
                    .resizable()
                    .aspectRatio(contentMode: .fit)
            } else if failed {
                placeholder(systemName: "photo.badge.exclamationmark")
            } else {
                placeholder(systemName: "photo")
            }
        }
        .task(id: gameServerId) {
            await load()
        }
    }

    private func placeholder(systemName: String) -> some View {
        Rectangle()
            .fill(Color.gray.opacity(0.2))
            .overlay {
                Image(systemName: systemName)
                    .font(.title)
                    .foregroundStyle(.secondary)
            }
            .aspectRatio(2.0/3.0, contentMode: .fit)
    }

    private func load() async {
        guard let id = gameServerId else { return }
        do {
            let url = try await api.downloadCover(gameServerId: id, face: face, size: size)
            await MainActor.run {
                self.localURL = url
                self.failed = false
            }
        } catch {
            await MainActor.run { self.failed = true }
        }
    }
}
