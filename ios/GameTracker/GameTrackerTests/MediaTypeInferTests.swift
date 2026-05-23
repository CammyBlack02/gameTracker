import XCTest
@testable import GameTracker

final class MediaTypeInferTests: XCTestCase {

    func test_modern_disc_consoles_are_disc() {
        XCTAssertEqual(MediaTypeInfer.infer(from: "PlayStation 5"),       .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PlayStation 4"),       .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Xbox Series X"),       .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Xbox One"),            .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "GameCube"),            .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Wii"),                 .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Wii U"),               .disc)
    }

    func test_cart_consoles_are_cart() {
        XCTAssertEqual(MediaTypeInfer.infer(from: "Nintendo Switch"),     .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Nintendo 3DS"),        .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Nintendo DS"),         .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Game Boy Advance"),    .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Game Boy Color"),      .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PlayStation Vita"),    .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "GBA"),                 .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "SNES"),                .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "N64"),                 .cart)
    }

    func test_case_insensitive() {
        XCTAssertEqual(MediaTypeInfer.infer(from: "nintendo switch"),     .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PLAYSTATION 5"),       .disc)
    }

    func test_pc_defaults_to_disc() {
        XCTAssertEqual(MediaTypeInfer.infer(from: "PC"),                  .disc)
    }

    func test_empty_string_defaults_to_disc() {
        XCTAssertEqual(MediaTypeInfer.infer(from: ""),                    .disc)
    }

    func test_partial_match_with_super_nintendo_label() {
        // "Super Nintendo (SNES)" should still hit the SNES keyword.
        XCTAssertEqual(MediaTypeInfer.infer(from: "Super Nintendo (SNES)"), .cart)
    }
}
