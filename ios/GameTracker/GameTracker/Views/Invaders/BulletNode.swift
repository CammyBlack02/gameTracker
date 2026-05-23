import SpriteKit
import UIKit

final class BulletNode: SKSpriteNode {

    enum Kind { case player, invader }

    let kind: Kind
    let velocity: CGVector

    init(kind: Kind) {
        self.kind = kind
        let speed: CGFloat = (kind == .player) ? 500 : 250
        self.velocity = CGVector(dx: 0, dy: (kind == .player) ? speed : -speed)
        let texture = SKTexture(image: Self.makeTextureImage(kind: kind))
        super.init(texture: texture,
                   color: .clear,
                   size: CGSize(width: 2, height: 8))
        self.name = "bullet"
    }

    required init?(coder aDecoder: NSCoder) { fatalError("unsupported") }

    private static func makeTextureImage(kind: Kind) -> UIImage {
        let size = CGSize(width: 2, height: 8)
        return UIGraphicsImageRenderer(size: size).image { ctx in
            let color: UIColor = (kind == .player) ? .label : .systemRed
            ctx.cgContext.setFillColor(color.cgColor)
            ctx.cgContext.fill(CGRect(origin: .zero, size: size))
        }
    }
}
