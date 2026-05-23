import UIKit
import SpriteKit
import SwiftUI

/// Generates a tall, narrow `UIImage` for use as the spine texture of
/// a `CoverFlowCaseNode`. The title is rotated 90° so it reads
/// bottom-to-top when the box stands upright.
@MainActor
enum SpineTextureBuilder {

    /// Returns nil on snapshot failure (very rare; mainly for safety).
    static func makeSpine(title: String,
                          background: Color,
                          fontName: String? = nil,
                          width: CGFloat = 60,
                          height: CGFloat = 600) -> UIImage? {
        let scene = SKScene(size: CGSize(width: width, height: height))
        scene.scaleMode = .resizeFill
        scene.backgroundColor = UIColor(background)

        // Compose the title node, rotated 90° (counter-clockwise) so
        // text runs from the bottom toward the top.
        let label = SKLabelNode(text: title)
        label.fontColor = .white
        label.fontSize = 22
        label.fontName = resolvedFontName(preferred: fontName)
        label.verticalAlignmentMode = .center
        label.horizontalAlignmentMode = .center
        label.position = CGPoint(x: width / 2, y: height / 2)
        label.zRotation = .pi / 2   // 90° anti-clockwise
        // Clamp the displayed width so very long titles don't render
        // off the spine. SpriteKit doesn't truncate natively; we cap
        // text width via a length-based scale.
        scaleLabelToFit(label, maxLength: height - 40)
        scene.addChild(label)

        // Snapshot via an offscreen SKView.
        let view = SKView(frame: CGRect(origin: .zero,
                                         size: CGSize(width: width, height: height)))
        view.allowsTransparency = false
        view.isOpaque = true
        let texture = view.texture(from: scene)
        guard let cg = texture?.cgImage() else { return nil }
        return UIImage(cgImage: cg, scale: UIScreen.main.scale, orientation: .up)
    }

    /// Returns the bundled custom font name if it exists and the
    /// preference is set; otherwise falls back to a system bold font.
    private static func resolvedFontName(preferred: String?) -> String {
        if let name = preferred, UIFont(name: name, size: 12) != nil {
            return name
        }
        return "AvenirNext-Bold"   // a system font reliably present
    }

    /// Roughly scales the label so its rotated content fits the
    /// spine's long axis. Imperfect but good enough for v1.
    private static func scaleLabelToFit(_ label: SKLabelNode,
                                        maxLength: CGFloat) {
        guard label.frame.width > maxLength else { return }
        let scale = maxLength / label.frame.width
        label.fontSize *= max(scale, 0.4)   // floor at 40% so very long titles stay legible
    }
}
