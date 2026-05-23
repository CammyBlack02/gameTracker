import SceneKit
import SwiftUI

/// Manages the SCNScene's case row: layout math, recycling of off-screen
/// boxes, and animation between focused indices.
@MainActor
final class CoverFlowScene {

    let scene: SCNScene
    let cameraNode: SCNNode
    let caseRow: SCNNode

    /// Games array as last seen. The scene rebuilds case nodes when
    /// this changes.
    private(set) var games: [Game] = []

    /// Index of the currently focused (centered) game.
    private(set) var focusedIndex: Int = 0

    /// Whether the focused box is rotated 180° to show its back cover.
    /// Always resets to false when focus moves to a different game.
    private(set) var focusedShowingBack: Bool = false

    /// Visible-window radius. Boxes more than `windowRadius` cases
    /// away from `focusedIndex` are removed from the scene.
    private let windowRadius = 3

    /// Currently realized case nodes, keyed by game index.
    private var caseNodes: [Int: SCNNode] = [:]

    private let imagesAPI: ImagesAPI

    init(imagesAPI: ImagesAPI) {
        self.imagesAPI = imagesAPI

        scene = SCNScene()
        scene.background.contents = UIColor.clear

        caseRow = SCNNode()
        scene.rootNode.addChildNode(caseRow)

        // Camera — wider FoV gives more visible perspective skew on
        // the rotated side boxes. Distance kept close (1.2) so the
        // focused box reads at a comfortable on-screen size.
        let cam = SCNCamera()
        cam.fieldOfView = 65
        cam.zNear = 0.1
        cameraNode = SCNNode()
        cameraNode.camera = cam
        cameraNode.position = SCNVector3(0, 0, 1.2)
        scene.rootNode.addChildNode(cameraNode)

        // Lighting: soft ambient floor for the unlit faces, plus a
        // key directional light from upper-right so the front face and
        // the visible side spines of the rotated neighbours receive
        // measurably different illumination (the shading falloff is
        // what reads as "this is a 3D box, not a sticker"). The SCNView
        // also enables default lighting on top for a forgiving fill.
        let ambient = SCNLight()
        ambient.type = .ambient
        ambient.intensity = 350
        let ambientNode = SCNNode()
        ambientNode.light = ambient
        scene.rootNode.addChildNode(ambientNode)

        let key = SCNLight()
        key.type = .directional
        key.intensity = 900
        let keyNode = SCNNode()
        keyNode.light = key
        // Pitch -20°, yaw +35° → light from upper-right of camera.
        keyNode.eulerAngles = SCNVector3(-Float.pi / 9,
                                          Float.pi / 5,
                                          0)
        scene.rootNode.addChildNode(keyNode)
    }

    // MARK: - Public API

    func update(games: [Game]) {
        self.games = games

        // Reset focused index if it now falls outside the array.
        if focusedIndex >= games.count {
            focusedIndex = max(0, games.count - 1)
        }

        rebuildVisibleNodes()
    }

    /// Smoothly snap the row to a new focused index.
    func snap(to index: Int, animated: Bool = true) {
        let clamped = max(0, min(games.count - 1, index))
        if clamped != focusedIndex {
            // Focus moved → drop any flip state from the previous box.
            focusedShowingBack = false
        }
        focusedIndex = clamped

        SCNTransaction.begin()
        SCNTransaction.animationDuration = animated ? 0.35 : 0.0
        rebuildVisibleNodes()
        SCNTransaction.commit()
    }

    /// Toggle / set whether the focused box is rotated to show its back.
    /// Animates the yaw change over the standard snap duration.
    func setFocusedShowingBack(_ showing: Bool) {
        guard focusedShowingBack != showing else { return }
        focusedShowingBack = showing

        SCNTransaction.begin()
        SCNTransaction.animationDuration = 0.45
        if let node = caseNodes[focusedIndex] {
            applyTransform(to: node, offset: 0)
        }
        SCNTransaction.commit()
    }

    /// Game at a given index (or nil if out of range).
    func game(at index: Int) -> Game? {
        guard index >= 0, index < games.count else { return nil }
        return games[index]
    }

    /// Find the index of a node by name, if any.
    func index(of nodeName: String?) -> Int? {
        guard let name = nodeName else { return nil }
        return caseNodes.first { _, node in node.name == name }?.key
    }

    // MARK: - Layout

    private func rebuildVisibleNodes() {
        guard !games.isEmpty else {
            caseNodes.values.forEach { $0.removeFromParentNode() }
            caseNodes.removeAll()
            return
        }

        let lo = max(0, focusedIndex - windowRadius)
        let hi = min(games.count - 1, focusedIndex + windowRadius)

        // Remove nodes outside the new window.
        for (i, node) in caseNodes where i < lo || i > hi {
            node.removeFromParentNode()
            caseNodes.removeValue(forKey: i)
        }

        // Add nodes for newly-visible indices.
        for i in lo...hi where caseNodes[i] == nil {
            guard let game = game(at: i) else { continue }
            let node = CoverFlowCaseNode.make(game: game,
                                              imagesAPI: imagesAPI)
            caseRow.addChildNode(node)
            caseNodes[i] = node
        }

        // Position + rotate all visible nodes based on offset from focus.
        for (i, node) in caseNodes {
            let offset = i - focusedIndex
            applyTransform(to: node, offset: offset)
        }
    }

    /// Map indexOffset → (position, rotation, scale, opacity).
    private func applyTransform(to node: SCNNode, offset: Int) {
        let absOff = abs(offset)
        let sign = Float(offset.signum())   // -1, 0, or +1

        // Translation
        let x: Float
        let y: Float
        let z: Float
        switch absOff {
        case 0: (x, y, z) = (0,           0.15,  0.15)
        case 1: (x, y, z) = (0.55 * sign, 0,     0)
        case 2: (x, y, z) = (0.95 * sign, 0,    -0.05)
        default: (x, y, z) = (1.5 * sign,  0,    -0.10)
        }
        node.position = SCNVector3(x, y, z)

        // Rotation around Y (anti-clockwise for positive offsets, so
        // boxes to the right face left). The focused box adds an extra
        // 180° when the user has tapped "flip" to view the back cover.
        let yaw: Float
        switch absOff {
        case 0: yaw = focusedShowingBack ? .pi : 0
        case 1: yaw = -sign * (.pi / 4)         // 45°
        case 2: yaw = -sign * (.pi / 180 * 55)  // 55°
        default: yaw = -sign * (.pi / 180 * 60)
        }
        node.eulerAngles = SCNVector3(0, yaw, 0)

        // Scale
        let scale: Float
        switch absOff {
        case 0: scale = 1.0
        case 1: scale = 0.85
        case 2: scale = 0.75
        default: scale = 0.6
        }
        node.scale = SCNVector3(scale, scale, scale)

        // Opacity
        node.opacity = CGFloat(max(0, 1.0 - Double(absOff) * 0.25))
    }
}
