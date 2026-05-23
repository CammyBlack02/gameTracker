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

    /// Box dimensions in SceneKit "units", roughly scaled from real
    /// case sizes (DVD ≈ 135×190×14 mm as baseline):
    ///   - disc:  tall, thin (PS2+, Xbox, Wii, GameCube)
    ///   - cart:  squarer, chunkier (Switch, 3DS, GBA, NES)
    ///   - jewel: wider than tall, CD-thin (PS1, Dreamcast, Saturn)
    private static let discSize  = SCNVector3(0.37, 0.52, 0.030)
    private static let cartSize  = SCNVector3(0.35, 0.38, 0.050)
    private static let jewelSize = SCNVector3(0.37, 0.33, 0.025)

    /// Solid placeholder colors used until cover textures load.
    private static let placeholderFront  = UIColor(white: 0.85, alpha: 1.0)
    private static let placeholderDark   = UIColor(white: 0.10, alpha: 1.0)

    /// Build the node. Cover textures are kicked off as async work;
    /// the returned node renders with placeholders immediately and
    /// updates in place when the images arrive.
    static func make(game: Game, imagesAPI: ImagesAPI) -> SCNNode {
        let size: SCNVector3
        switch MediaTypeInfer.infer(from: game.platform) {
        case .disc:  size = discSize
        case .cart:  size = cartSize
        case .jewel: size = jewelSize
        }

        let box = SCNBox(width: CGFloat(size.x),
                         height: CGFloat(size.y),
                         length: CGFloat(size.z),
                         chamferRadius: 0.004)
        box.materials = makeInitialMaterials()

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

    private static func makeInitialMaterials() -> [SCNMaterial] {
        // Spines are pure black: real game spines are part of the
        // wraparound back-cover artwork uploaded by the user, so a
        // generated spine here would either duplicate or compete with
        // it. Black reads cleanly as the case's edge.
        return [
            material(contents: placeholderFront), // 0 front (cover)
            material(contents: UIColor.black),    // 1 right spine
            material(contents: placeholderFront), // 2 back (cover)
            material(contents: UIColor.black),    // 3 left spine
            material(contents: placeholderDark),  // 4 top
            material(contents: placeholderDark),  // 5 bottom
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
        guard let raw = UIImage(contentsOfFile: url.path) else { return nil }
        return downscaleForMetal(raw)
    }

    /// Metal's MTLTextureDescriptor caps texture dimensions at 8192px on
    /// current iPhone GPUs. Phone-camera photos routinely exceed that
    /// (e.g. a 12MP shot at 9675×7256). Hand SCNMaterial one of those
    /// and the simulator's Metal validation layer aborts on assignment.
    /// Pre-shrink anything larger than `maxDim` before it touches the
    /// scene graph.
    private static func downscaleForMetal(_ image: UIImage,
                                          maxDim: CGFloat = 2048) -> UIImage {
        let widthPx  = image.size.width  * image.scale
        let heightPx = image.size.height * image.scale
        let longest  = max(widthPx, heightPx)
        guard longest > maxDim else { return image }

        let factor = maxDim / longest
        let target = CGSize(width:  image.size.width  * factor,
                            height: image.size.height * factor)
        let format = UIGraphicsImageRendererFormat.default()
        format.scale = 1   // we already chose target in points; don't double-scale.
        let renderer = UIGraphicsImageRenderer(size: target, format: format)
        return renderer.image { _ in
            image.draw(in: CGRect(origin: .zero, size: target))
        }
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
