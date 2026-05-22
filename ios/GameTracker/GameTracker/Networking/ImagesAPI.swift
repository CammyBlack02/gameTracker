import Foundation

/// Downloads cover and extra-photo images from the server, caching them
/// on disk so subsequent requests are instant.
///
/// Cache strategy (per the spec):
///   - Cover thumbnails → Documents/covers/  (backed up to iCloud)
///   - Full-res covers → Caches/covers-full/ (evictable by iOS)
///   - Extra thumbs    → Documents/extras/
///   - Extra full-res  → Caches/extras-full/
///
/// `cacheRoot` is the directory under which we maintain the four
/// subdirectories. In production we pass the app's Documents dir;
/// tests inject a temporary directory.
struct ImagesAPI {

    enum Face: String { case front, back }
    enum Size: String { case thumb, full }
    enum ExtraType: String { case game, item }

    let client: APIClient
    let cacheRoot: URL

    init(client: APIClient, cacheRoot: URL) {
        self.client = client
        self.cacheRoot = cacheRoot
        try? FileManager.default.createDirectory(at: cacheRoot, withIntermediateDirectories: true)
    }

    /// Returns a local file URL pointing at the cached cover image.
    /// Downloads if not already on disk.
    func downloadCover(gameServerId: Int, face: Face, size: Size) async throws -> URL {
        let filename = "cover_\(gameServerId)_\(face.rawValue)_\(size.rawValue).jpg"
        let dest = cacheRoot.appendingPathComponent(filename)
        if FileManager.default.fileExists(atPath: dest.path) { return dest }

        let data = try await client.downloadData(
            "/api/v2/images/cover.php",
            query: ["id": String(gameServerId), "face": face.rawValue, "size": size.rawValue]
        )
        try data.write(to: dest, options: .atomic)
        return dest
    }

    /// Mirror of `downloadCover(gameServerId:…)` but hits the same
    /// endpoint with `type=item`, looking up `items.front_image` /
    /// `items.back_image`. Cache filename is namespaced with `item_`
    /// so a game and item sharing a server ID never collide on disk.
    func downloadCover(itemServerId: Int, face: Face, size: Size) async throws -> URL {
        let filename = "item_\(itemServerId)_\(face.rawValue)_\(size.rawValue).jpg"
        let dest = cacheRoot.appendingPathComponent(filename)
        if FileManager.default.fileExists(atPath: dest.path) { return dest }

        let data = try await client.downloadData(
            "/api/v2/images/cover.php",
            query: ["id": String(itemServerId), "type": "item", "face": face.rawValue, "size": size.rawValue]
        )
        try data.write(to: dest, options: .atomic)
        return dest
    }

    /// Same pattern for extra photos. `type` selects game_images vs item_images.
    func downloadExtra(imageServerId: Int, type: ExtraType, size: Size) async throws -> URL {
        let filename = "extra_\(type.rawValue)_\(imageServerId)_\(size.rawValue).jpg"
        let dest = cacheRoot.appendingPathComponent(filename)
        if FileManager.default.fileExists(atPath: dest.path) { return dest }

        let data = try await client.downloadData(
            "/api/v2/images/extra.php",
            query: ["id": String(imageServerId), "type": type.rawValue, "size": size.rawValue]
        )
        try data.write(to: dest, options: .atomic)
        return dest
    }

    /// Manual purge — used by the eventual "Clear cache" Settings button.
    func clearCache() throws {
        let contents = try FileManager.default.contentsOfDirectory(at: cacheRoot, includingPropertiesForKeys: nil)
        for url in contents { try FileManager.default.removeItem(at: url) }
    }

    /// Purge cached item cover files for one item (both faces, both sizes).
    /// Called by Add/Edit save paths after writing a new data URI into
    /// `item.frontImage` so the next render fetches the new bytes instead
    /// of returning the stale cached file.
    func invalidateItemCover(itemServerId: Int) {
        for face in [Face.front, Face.back] {
            for size in [Size.thumb, Size.full] {
                let filename = "item_\(itemServerId)_\(face.rawValue)_\(size.rawValue).jpg"
                let dest = cacheRoot.appendingPathComponent(filename)
                try? FileManager.default.removeItem(at: dest)
            }
        }
    }
}

/// Locations on disk corresponding to the spec's four caches.
enum ImageCachePaths {
    static var coversThumbs: URL { docs("covers") }
    static var coversFull: URL { caches("covers-full") }
    static var extrasThumbs: URL { docs("extras") }
    static var extrasFull: URL { caches("extras-full") }

    private static func docs(_ name: String) -> URL {
        let base = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]
        return base.appendingPathComponent(name)
    }
    private static func caches(_ name: String) -> URL {
        let base = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask)[0]
        return base.appendingPathComponent(name)
    }
}
