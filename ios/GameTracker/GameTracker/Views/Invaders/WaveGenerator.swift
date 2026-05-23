import CoreGraphics

struct WaveConfig: Equatable {
    let rows: Int
    let cols: Int
    let speed: CGFloat       // points / second
    let fireRate: CGFloat    // bullets / second across the whole grid
}

/// Deterministic difficulty ramp. From a baseline at wave 1, each
/// subsequent wave advances one parameter on a 3-way cycle:
///   w % 3 == 2 → +1 row (cap at 6)
///   w % 3 == 0 → speed ×1.12
///   w % 3 == 1 → fireRate ×1.15
enum WaveGenerator {

    private static let baselineRows: Int = 4
    private static let baselineSpeed: CGFloat = 30
    private static let baselineFire: CGFloat = 0.4

    static func config(for wave: Int) -> WaveConfig {
        var rows = baselineRows
        var speed = baselineSpeed
        var fireRate = baselineFire

        if wave >= 2 {
            for w in 2...wave {
                switch w % 3 {
                case 2: rows = min(rows + 1, 6)
                case 0: speed *= 1.12
                default: fireRate *= 1.15
                }
            }
        }

        return WaveConfig(rows: rows, cols: 6, speed: speed, fireRate: fireRate)
    }
}
