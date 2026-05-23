import UIKit
import SceneKit
import SwiftData
import SwiftUI

/// Builds a single textured `SCNNode` representing one game's box.
/// The node has a `name` set to the persistent ID string so hit-tests
/// can map back to the SwiftData row.
///
/// Box face order (Apple's SCNBox materialsArray indexing):
///   0: front (+Z)
///   1: right (+X) — spine
///   2: back  (-Z)
///   3: left  (-X) — spine
///   4: top   (+Y)
///   5: bottom (-Y)
@MainActor
enum CoverFlowCaseNode {

    /// Box dimensions in SceneKit "units". The camera + lighting
    /// elsewhere are tuned for these values.
    private static let discSize  = SCNVector3(0.28, 0.40, 0.022)
    private static let cartSize  = SCNVector3(0.22, 0.32, 0.018)

    /// Solid placeholder colors used until textures load.
    private static let placeholderFront  = UIColor(white: 0.85, alpha: 1.0)
    private static let placeholderDark   = UIColor(white: 0.10, alpha: 1.0)

    /// Build the node. Cover textures are kicked off as async work;
    /// the returned node renders with placeholders immediately and
    /// updates in place when the images arrive.
    static func make(game: Game,
                     imagesAPI: ImagesAPI,
                     theme: Theme) -> SCNNode {
        let media = MediaTypeInfer.infer(from: game.platform)
        let size  = (media == .cart) ? cartSize : discSize

        let box = SCNBox(width: CGFloat(size.x),
                         height: CGFloat(size.y),
                         length: CGFloat(size.z),
                         chamferRadius: 0.004)
        box.materials = makeInitialMaterials(game: game, theme: theme)

        let node = SCNNode(geometry: box)
        node.name = nodeName(for: game)

        // Kick off async cover loads. Failures are logged and the
        // placeholder stays.
        Task { @MainActor in
            await applyCoverTextures(to: node, game: game, imagesAPI: imagesAPI)
        }
        return node
    }

    /// Stable identifier string for the node so hit-tests can map back
    /// to the originating game. PersistentIdentifier is Hashable so its
    /// hashValue is stable for the lifetime of the process.
    static func nodeName(for game: Game) -> String {
        "game-\(game.persistentModelID.hashValue)"
    }

    // MARK: - Material setup

    private static func makeInitialMaterials(game: Game, theme: Theme) -> [SCNMaterial] {
        let spineImage = SpineTextureBuilder.makeSpine(
            title: game.title,
            background: theme.accent,
            fontName: theme.fontName
        )

        return [
            material(contents: placeholderFront),              // 0 front
            material(contents: spineImage ?? placeholderDark), // 1 right spine
            material(contents: placeholderFront),              // 2 back
            material(contents: spineImage ?? placeholderDark), // 3 left spine
            material(contents: placeholderDark),               // 4 top
            material(contents: placeholderDark),               // 5 bottom
        ]
    }

    private static func material(contents: Any) -> SCNMaterial {
        let m = SCNMaterial()
        m.diffuse.contents = contents
        m.locksAmbientWithDiffuse = true
        return m
    }

    // MARK: - Async cover loading

    private static func applyCoverTextures(to node: SCNNode,
                                           game: Game,
                                           imagesAPI: ImagesAPI) async {
        guard let serverId = game.serverId else { return }
        // Front
        if let front = await loadImage(imagesAPI: imagesAPI,
                                       serverId: serverId,
                                       face: .front) {
            node.geometry?.materials[0].diffuse.contents = front
        }
        // Back — fall back to dimmed front if back not set.
        if let back = await loadImage(imagesAPI: imagesAPI,
                                      serverId: serverId,
                                      face: .back) {
            node.geometry?.materials[2].diffuse.contents = back
        } else if let frontFallback = node.geometry?.materials[0].diffuse.contents as? UIImage {
            node.geometry?.materials[2].diffuse.contents = dim(frontFallback, by: 0.6)
        }
    }

    private static func loadImage(imagesAPI: ImagesAPI,
                                  serverId: Int,
                                  face: ImagesAPI.Face) async -> UIImage? {
        guard let url = try? await imagesAPI.downloadCover(
            gameServerId: serverId, face: face, size: .full
        ) else { return nil }
        return UIImage(contentsOfFile: url.path)
    }

    /// Multiplies the image's pixels toward black. Used as a back-cover
    /// fallback when no real back-cover is available.
    private static func dim(_ image: UIImage, by factor: CGFloat) -> UIImage? {
        guard let cg = image.cgImage else { return nil }
        let size = CGSize(width: cg.width, height: cg.height)
        let renderer = UIGraphicsImageRenderer(size: size)
        return renderer.image { ctx in
            image.draw(in: CGRect(origin: .zero, size: size))
            ctx.cgContext.setFillColor(UIColor(white: 0, alpha: factor).cgColor)
            ctx.cgContext.fill(CGRect(origin: .zero, size: size))
        }
    }
}
