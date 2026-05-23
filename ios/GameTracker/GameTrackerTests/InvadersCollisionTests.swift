import XCTest
import CoreGraphics
@testable import GameTracker

final class InvadersCollisionTests: XCTestCase {

    func test_identical_rects_overlap() {
        let r = CGRect(x: 10, y: 20, width: 40, height: 30)
        XCTAssertTrue(CollisionMath.rectsOverlap(r, r))
    }

    func test_edge_touching_rects_do_not_overlap() {
        let a = CGRect(x: 0, y: 0, width: 10, height: 10)
        let b = CGRect(x: 10, y: 0, width: 10, height: 10)
        XCTAssertFalse(CollisionMath.rectsOverlap(a, b))
    }

    func test_disjoint_rects_do_not_overlap() {
        let a = CGRect(x: 0, y: 0, width: 10, height: 10)
        let b = CGRect(x: 100, y: 100, width: 10, height: 10)
        XCTAssertFalse(CollisionMath.rectsOverlap(a, b))
    }

    func test_partially_overlapping_rects_overlap() {
        let a = CGRect(x: 0, y: 0, width: 20, height: 20)
        let b = CGRect(x: 10, y: 10, width: 20, height: 20)
        XCTAssertTrue(CollisionMath.rectsOverlap(a, b))
    }

    func test_nested_rects_overlap() {
        let outer = CGRect(x: 0, y: 0, width: 100, height: 100)
        let inner = CGRect(x: 40, y: 40, width: 10, height: 10)
        XCTAssertTrue(CollisionMath.rectsOverlap(outer, inner))
    }
}
