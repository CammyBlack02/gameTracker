import SwiftUI

/// Async-loaded cover image with a placeholder. Uses `ImagesAPI`'s
/// on-disk cache, so subsequent renders for the same (subject,
/// face, size) are instant.
///
/// Supports both game covers and item covers via two convenience
/// inits — internally the view discriminates with the `Subject` enum
/// and dispatches to the matching `ImagesAPI.downloadCover` overload.
struct CoverImage: View {

    /// Which kind of resource to fetch. `nil` ID means "no image yet"
    /// (typically the row is still `.localNew` and unpushed) — the
    /// view renders the empty placeholder.
    private enum Subject: Equatable {
        case game(Int?)
        case item(Int?)
    }

    /// Composite key for `.task(id:)`. Triggers a reload whenever
    /// either the subject (game vs item, server id) or the face
    /// (front vs back) changes.
    private struct LoadKey: Equatable {
        let subject: Subject
        let face: ImagesAPI.Face
    }

    private let subject: Subject
    let face: ImagesAPI.Face
    let size: ImagesAPI.Size
    let api: ImagesAPI

    @State private var localURL: URL?
    @State private var failed = false
    @Environment(\.theme) private var theme

    init(gameServerId: Int?,
         face: ImagesAPI.Face = .front,
         size: ImagesAPI.Size = .thumb,
         api: ImagesAPI) {
        self.subject = .game(gameServerId)
        self.face = face
        self.size = size
        self.api = api
    }

    init(itemServerId: Int?,
         face: ImagesAPI.Face = .front,
         size: ImagesAPI.Size = .thumb,
         api: ImagesAPI) {
        self.subject = .item(itemServerId)
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
        .modifier(GameBoyDitherIfApplicable(theme: theme, size: size))
        .task(id: LoadKey(subject: subject, face: face)) {
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
        do {
            let url: URL
            switch subject {
            case .game(let id):
                guard let id else { return }
                url = try await api.downloadCover(gameServerId: id, face: face, size: size)
            case .item(let id):
                guard let id else { return }
                url = try await api.downloadCover(itemServerId: id, face: face, size: size)
            }
            await MainActor.run {
                self.localURL = url
                self.failed = false
            }
        } catch {
            // Network or server unavailable. When the caller asked for
            // `.full` but we already have the `.thumb` on disk (from a
            // previous Sync now preload or list-view render), surface
            // that instead of the error placeholder. The image will look
            // slightly less crisp than the original but it's far better
            // than a broken-photo icon while offline.
            let fallback = offlineThumbFallback()
            await MainActor.run {
                if let fallback {
                    self.localURL = fallback
                    self.failed = false
                } else {
                    self.failed = true
                }
            }
        }
    }

    private func offlineThumbFallback() -> URL? {
        guard size != .thumb else { return nil }
        switch subject {
        case .game(let id):
            guard let id else { return nil }
            return api.cachedCoverPath(gameServerId: id, face: face, size: .thumb)
        case .item(let id):
            guard let id else { return nil }
            return api.cachedItemCoverPath(itemServerId: id, face: face, size: .thumb)
        }
    }
}

/// Applies the Game Boy 4-color dither shader on `.full` size renders
/// when the active theme requests it. No-op otherwise.
private struct GameBoyDitherIfApplicable: ViewModifier {
    let theme: Theme
    let size: ImagesAPI.Size

    func body(content: Content) -> some View {
        if theme.coverEffect == .gameBoyDither && size == .full {
            content.colorEffect(ShaderLibrary.gameBoyDither())
        } else {
            content
        }
    }
}
