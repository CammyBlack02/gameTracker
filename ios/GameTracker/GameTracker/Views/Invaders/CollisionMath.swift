import CoreGraphics

/// Pure axis-aligned bounding-box overlap test, extracted from
/// InvadersScene so the collision logic can be unit-tested without
/// mounting a SpriteKit scene. Strict — rectangles that just touch
/// on an edge are considered disjoint.
enum CollisionMath {
    static func rectsOverlap(_ a: CGRect, _ b: CGRect) -> Bool {
        return a.maxX > b.minX &&
               a.minX < b.maxX &&
               a.maxY > b.minY &&
               a.minY < b.maxY
    }
}
