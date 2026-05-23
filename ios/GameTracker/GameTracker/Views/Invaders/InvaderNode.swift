import SpriteKit
import SwiftData
import UIKit

/// Sprite representing one game in the invader grid. Sized to a
/// nominal 48pt height with aspect-correct width so portrait cover
/// art retains its proportions.
final class InvaderNode: SKSpriteNode {

    static let nominalHeight: CGFloat = 48

    /// Persistent ID of the originating game. Used by the scene to
    /// re-texture this invader if its real cover arrives mid-game.
    let gameID: PersistentIdentifier

    /// Score awarded when this invader is destroyed. Set by the
    /// scene at wave-start as `10 * waveNumber`.
    var pointValue: Int = 10

    init(gameID: PersistentIdentifier,
         cover: UIImage?,
         platformLabel: String) {
        self.gameID = gameID
        let img = cover ?? Self.makePlaceholder(letter: platformLabel)
        let texture = SKTexture(image: img)
        super.init(texture: texture,
                   color: .clear,
                   size: Self.sizeFor(imageSize: img.size))
        self.name = "invader"
    }

    required init?(coder aDecoder: NSCoder) { fatalError("unsupported") }

    /// Replace this invader's texture once its real cover arrives.
    func applyCover(_ image: UIImage) {
        self.texture = SKTexture(image: image)
        self.size = Self.sizeFor(imageSize: image.size)
    }

    private static func sizeFor(imageSize size: CGSize) -> CGSize {
        let aspect = size.width / max(size.height, 1)
        return CGSize(width: nominalHeight * aspect, height: nominalHeight)
    }

    private static func makePlaceholder(letter: String) -> UIImage {
        let size = CGSize(width: 36, height: nominalHeight)
        return UIGraphicsImageRenderer(size: size).image { ctx in
            // Grey block
            ctx.cgContext.setFillColor(UIColor(white: 0.35, alpha: 1).cgColor)
            ctx.cgContext.fill(CGRect(origin: .zero, size: size))
            // First letter of the platform, centred
            let attrs: [NSAttributedString.Key: Any] = [
                .font: UIFont.monospacedSystemFont(ofSize: 20, weight: .bold),
                .foregroundColor: UIColor.white,
            ]
            let s = NSString(string: letter.prefix(1).uppercased())
            let textSize = s.size(withAttributes: attrs)
            s.draw(at: CGPoint(x: (size.width - textSize.width) / 2,
                               y: (size.height - textSize.height) / 2),
                   withAttributes: attrs)
        }
    }
}
