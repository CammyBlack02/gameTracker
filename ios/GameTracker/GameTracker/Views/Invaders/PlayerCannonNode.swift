import SpriteKit
import UIKit

/// Classic Space Invaders cannon silhouette at the bottom of the
/// scene. Texture is procedurally drawn — three filled rectangles for
/// base, body, and barrel — so the asset catalogue stays untouched.
final class PlayerCannonNode: SKSpriteNode {

    static let nominalSize = CGSize(width: 36, height: 22)

    init() {
        let texture = SKTexture(image: Self.makeTextureImage())
        super.init(texture: texture, color: .clear, size: Self.nominalSize)
        self.name = "player"
    }

    required init?(coder aDecoder: NSCoder) { fatalError("unsupported") }

    private static func makeTextureImage() -> UIImage {
        let size = nominalSize
        return UIGraphicsImageRenderer(size: size).image { ctx in
            ctx.cgContext.setFillColor(UIColor.label.cgColor)
            // Base (wide rectangle at the bottom of the texture)
            ctx.cgContext.fill(CGRect(x: 0,
                                       y: size.height - 8,
                                       width: size.width,
                                       height: 8))
            // Body (narrower rectangle above the base)
            ctx.cgContext.fill(CGRect(x: 8,
                                       y: size.height - 14,
                                       width: size.width - 16,
                                       height: 6))
            // Barrel (thin rectangle at the top centre)
            ctx.cgContext.fill(CGRect(x: size.width / 2 - 2,
                                       y: 0,
                                       width: 4,
                                       height: 8))
        }
    }
}
