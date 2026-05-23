import XCTest
import CoreGraphics
@testable import GameTracker

final class WaveGeneratorTests: XCTestCase {

    func test_wave_1_is_baseline() {
        let c = WaveGenerator.config(for: 1)
        XCTAssertEqual(c.rows, 4)
        XCTAssertEqual(c.cols, 6)
        XCTAssertEqual(c.speed, 30, accuracy: 0.001)
        XCTAssertEqual(c.fireRate, 0.4, accuracy: 0.001)
    }

    func test_wave_2_adds_a_row() {
        // w=2 → w%3==2 → +1 row
        XCTAssertEqual(WaveGenerator.config(for: 2).rows, 5)
    }

    func test_wave_3_bumps_speed() {
        // w=3 → w%3==0 → speed ×1.12
        let c = WaveGenerator.config(for: 3)
        XCTAssertEqual(c.rows, 5)
        XCTAssertEqual(c.speed, 30 * 1.12, accuracy: 0.001)
    }

    func test_wave_4_bumps_fire_rate() {
        // w=4 → w%3==1 → fireRate ×1.15
        XCTAssertEqual(WaveGenerator.config(for: 4).fireRate,
                       0.4 * 1.15,
                       accuracy: 0.001)
    }

    func test_rows_cap_at_six() {
        // Row bumps at w=2 (→5), w=5 (→6), then capped.
        XCTAssertEqual(WaveGenerator.config(for: 50).rows, 6)
    }

    func test_speed_strictly_increases_after_three_waves() {
        XCTAssertGreaterThan(WaveGenerator.config(for: 6).speed,
                             WaveGenerator.config(for: 3).speed)
    }

    func test_fire_rate_strictly_increases_after_three_waves() {
        XCTAssertGreaterThan(WaveGenerator.config(for: 7).fireRate,
                             WaveGenerator.config(for: 4).fireRate)
    }
}
