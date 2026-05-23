import UIKit
import SwiftData

/// Loads cover thumbnails as `UIImage` for SpriteKit sprites, backed by
/// the existing ImagesAPI thumb cache. Memory-bounded: holds at most
/// `cap` unique textures keyed by `persistentModelID`, evicting in
/// least-recently-used order when full.
@MainActor
final class CoverTextureLoader {

    private let imagesAPI: ImagesAPI
    private let cap: Int
    private var cache: [PersistentIdentifier: UIImage] = [:]
    private var order: [PersistentIdentifier] = []

    init(imagesAPI: ImagesAPI, cap: Int = 64) {
        self.imagesAPI = imagesAPI
        self.cap = cap
    }

    /// Returns the cached or freshly-loaded thumbnail. Returns nil
    /// when the cover hasn't been synced to local disk yet — the
    /// caller should keep the placeholder texture in that case.
    func fetch(game: Game) async -> UIImage? {
        let id = game.persistentModelID
        if let cached = cache[id] {
            touch(id)
            return cached
        }
        guard let serverId = game.serverId else { return nil }
        guard let url = try? await imagesAPI.downloadCover(
            gameServerId: serverId, face: .front, size: .thumb
        ) else { return nil }
        guard let img = UIImage(contentsOfFile: url.path) else { return nil }
        insert(id, image: img)
        return img
    }

    private func touch(_ id: PersistentIdentifier) {
        order.removeAll { $0 == id }
        order.append(id)
    }

    private func insert(_ id: PersistentIdentifier, image: UIImage) {
        cache[id] = image
        order.append(id)
        while order.count > cap {
            let evicted = order.removeFirst()
            cache.removeValue(forKey: evicted)
        }
    }
}
