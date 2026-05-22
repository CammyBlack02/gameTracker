import XCTest
import SwiftUI
@testable import GameTracker

final class ThemeRegistryTests: XCTestCase {

    // Regression guard: 4a's three modes still produce the same color scheme
    // as before (System=nil, Light=.light, Dark=.dark).
    func test_system_theme_has_nil_color_scheme() {
        XCTAssertNil(ThemeRegistry.theme(for: .system).colorScheme)
    }
    func test_light_theme_has_light_color_scheme() {
        XCTAssertEqual(ThemeRegistry.theme(for: .light).colorScheme, .light)
    }
    func test_dark_theme_has_dark_color_scheme() {
        XCTAssertEqual(ThemeRegistry.theme(for: .dark).colorScheme, .dark)
    }

    // Rich themes — each must produce a non-nil color scheme (rich themes
    // never "follow system" — they're prescriptive).
    func test_matrix_theme_is_dark() {
        XCTAssertEqual(ThemeRegistry.theme(for: .matrix).colorScheme, .dark)
    }
    func test_retro_mac_theme_is_light() {
        XCTAssertEqual(ThemeRegistry.theme(for: .retroMac).colorScheme, .light)
    }
    func test_game_boy_theme_is_light() {
        XCTAssertEqual(ThemeRegistry.theme(for: .gameBoy).colorScheme, .light)
    }
    func test_crt_amber_theme_is_dark() {
        XCTAssertEqual(ThemeRegistry.theme(for: .crtAmber).colorScheme, .dark)
    }

    // Flourish assignments must match the spec.
    func test_matrix_has_code_rain_flourish() {
        XCTAssertEqual(ThemeRegistry.theme(for: .matrix).flourish, .codeRain)
    }
    func test_retro_mac_has_platinum_bevel_flourish() {
        XCTAssertEqual(ThemeRegistry.theme(for: .retroMac).flourish, .platinumBevel)
    }
    func test_game_boy_has_no_flourish_but_dither_cover_effect() {
        XCTAssertNil(ThemeRegistry.theme(for: .gameBoy).flourish)
        XCTAssertEqual(ThemeRegistry.theme(for: .gameBoy).coverEffect, .gameBoyDither)
    }
    func test_crt_amber_has_scanlines_flourish() {
        XCTAssertEqual(ThemeRegistry.theme(for: .crtAmber).flourish, .scanlines)
    }

    // Default (.system) themes have no flourish and no cover effect.
    func test_system_theme_has_no_flourish_or_effect() {
        let t = ThemeRegistry.theme(for: .system)
        XCTAssertNil(t.flourish)
        XCTAssertEqual(t.coverEffect, .none)
    }

    // Raw-value backwards compatibility — 4a values are unchanged.
    func test_legacy_raw_values_are_stable() {
        XCTAssertEqual(AppearanceMode.system.rawValue, "system")
        XCTAssertEqual(AppearanceMode.light.rawValue, "light")
        XCTAssertEqual(AppearanceMode.dark.rawValue, "dark")
    }

    // New raw values use snake_case for multi-word cases (so the
    // serialized form is unambiguous and never collides with future
    // single-word additions).
    func test_new_raw_values() {
        XCTAssertEqual(AppearanceMode.matrix.rawValue, "matrix")
        XCTAssertEqual(AppearanceMode.retroMac.rawValue, "retro_mac")
        XCTAssertEqual(AppearanceMode.gameBoy.rawValue, "game_boy")
        XCTAssertEqual(AppearanceMode.crtAmber.rawValue, "crt_amber")
    }
}
