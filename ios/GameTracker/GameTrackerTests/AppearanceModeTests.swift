import XCTest
import SwiftUI
@testable import GameTracker

final class AppearanceModeTests: XCTestCase {

    func test_system_maps_to_nil_color_scheme() {
        XCTAssertNil(AppearanceMode.system.colorScheme)
    }

    func test_light_maps_to_light_color_scheme() {
        XCTAssertEqual(AppearanceMode.light.colorScheme, .light)
    }

    func test_dark_maps_to_dark_color_scheme() {
        XCTAssertEqual(AppearanceMode.dark.colorScheme, .dark)
    }

    func test_raw_values_are_stable() {
        XCTAssertEqual(AppearanceMode.system.rawValue, "system")
        XCTAssertEqual(AppearanceMode.light.rawValue, "light")
        XCTAssertEqual(AppearanceMode.dark.rawValue, "dark")
    }

    func test_all_cases_have_distinct_display_names() {
        let names = AppearanceMode.allCases.map(\.displayName)
        XCTAssertEqual(Set(names).count, AppearanceMode.allCases.count)
    }
}
