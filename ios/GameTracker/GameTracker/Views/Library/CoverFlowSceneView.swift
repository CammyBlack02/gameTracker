import SwiftUI
import SceneKit
import UIKit

struct CoverFlowSceneView: UIViewRepresentable {

    let games: [Game]
    let imagesAPI: ImagesAPI
    let theme: Theme
    @Binding var focusedIndex: Int
    /// Called when the user taps the currently-focused box.
    let onActivateFocused: () -> Void

    final class Coordinator: NSObject, UIGestureRecognizerDelegate {
        let scene: CoverFlowScene
        var pannedFromIndex: Int = 0
        var parent: CoverFlowSceneView

        init(parent: CoverFlowSceneView) {
            self.parent = parent
            self.scene = CoverFlowScene(imagesAPI: parent.imagesAPI,
                                         theme: parent.theme)
        }

        @objc func handlePan(_ pan: UIPanGestureRecognizer) {
            guard let view = pan.view else { return }
            let dx = pan.translation(in: view).x
            // Convert pan distance to a fractional index offset.
            // Empirical: ~120pt per case feels natural.
            let fractional = -Double(dx) / 120.0

            switch pan.state {
            case .began:
                pannedFromIndex = scene.focusedIndex
            case .changed:
                // Live preview during the pan: float index, no snap.
                let target = pannedFromIndex + Int(fractional.rounded())
                let clamped = max(0, min(scene.games.count - 1, target))
                if clamped != scene.focusedIndex {
                    scene.snap(to: clamped, animated: false)
                    parent.focusedIndex = clamped
                }
            case .ended, .cancelled:
                // Snap with animation to final index (with a velocity
                // kick — flick > 1500 pt/s adds an extra step).
                let velocity = pan.velocity(in: view).x
                var target = scene.focusedIndex
                if abs(velocity) > 1500 {
                    target += velocity < 0 ? 1 : -1
                }
                let clamped = max(0, min(scene.games.count - 1, target))
                scene.snap(to: clamped, animated: true)
                parent.focusedIndex = clamped
            default:
                break
            }
        }

        @objc func handleTap(_ tap: UITapGestureRecognizer) {
            guard let scnView = tap.view as? SCNView else { return }
            let location = tap.location(in: scnView)
            let hits = scnView.hitTest(location, options: nil)
            guard let hit = hits.first else { return }

            // Walk up the parent chain until we find a node our scene
            // tracks (by name).
            var node: SCNNode? = hit.node
            while let n = node {
                if let idx = scene.index(of: n.name) {
                    if idx == scene.focusedIndex {
                        parent.onActivateFocused()
                    } else {
                        scene.snap(to: idx, animated: true)
                        parent.focusedIndex = idx
                    }
                    return
                }
                node = n.parent
            }
        }
    }

    func makeCoordinator() -> Coordinator {
        Coordinator(parent: self)
    }

    func makeUIView(context: Context) -> SCNView {
        let scnView = SCNView(frame: .zero)
        scnView.scene = context.coordinator.scene.scene
        scnView.allowsCameraControl = false
        scnView.autoenablesDefaultLighting = false
        scnView.backgroundColor = .clear

        let pan = UIPanGestureRecognizer(target: context.coordinator,
                                          action: #selector(Coordinator.handlePan(_:)))
        pan.delegate = context.coordinator
        scnView.addGestureRecognizer(pan)

        let tap = UITapGestureRecognizer(target: context.coordinator,
                                          action: #selector(Coordinator.handleTap(_:)))
        scnView.addGestureRecognizer(tap)

        context.coordinator.scene.update(games: games)
        return scnView
    }

    func updateUIView(_ scnView: SCNView, context: Context) {
        // Update parent reference so coordinator's writeback uses the
        // latest binding closure.
        context.coordinator.parent = self
        context.coordinator.scene.updateTheme(theme)
        context.coordinator.scene.update(games: games)
        if context.coordinator.scene.focusedIndex != focusedIndex {
            context.coordinator.scene.snap(to: focusedIndex, animated: true)
        }
    }
}
