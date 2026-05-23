import XCTest
@testable import GameTracker

final class MediaTypeInferTests: XCTestCase {

    func test_disc_consoles_are_disc() {
        // Modern disc-format consoles.
        XCTAssertEqual(MediaTypeInfer.infer(from: "PlayStation 5"),       .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PlayStation 4"),       .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Xbox Series X"),       .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Xbox One"),            .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "GameCube"),            .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Wii"),                 .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Wii U"),               .disc)
        // Per library convention these are also rendered as disc cases:
        XCTAssertEqual(MediaTypeInfer.infer(from: "Nintendo Switch"),     .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PlayStation Vita"),    .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PSP"),                 .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Sega Mega Drive"),     .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Sega Genesis"),        .disc)
    }

    func test_cart_consoles_are_cart() {
        XCTAssertEqual(MediaTypeInfer.infer(from: "Nintendo 3DS"),        .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Nintendo DS"),         .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Game Boy Advance"),    .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Game Boy Color"),      .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "GBA"),                 .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "SNES"),                .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "N64"),                 .cart)
        XCTAssertEqual(MediaTypeInfer.infer(from: "NES"),                 .cart)
    }

    func test_jewel_case_consoles_are_jewel() {
        XCTAssertEqual(MediaTypeInfer.infer(from: "PlayStation"),         .jewel)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PlayStation 1"),       .jewel)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PS1"),                 .jewel)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PSX"),                 .jewel)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Dreamcast"),           .jewel)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Sega Saturn"),         .jewel)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Saturn"),              .jewel)
    }

    func test_case_insensitive() {
        XCTAssertEqual(MediaTypeInfer.infer(from: "nintendo 3ds"),        .cart)
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

    func test_bare_playstation_does_not_swallow_later_models() {
        // Specific PS keywords must beat the bare-"PlayStation" jewel rule.
        XCTAssertEqual(MediaTypeInfer.infer(from: "Sony PlayStation 4"),  .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PlayStation Vita"),    .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "PSP"),                 .disc)
    }

    func test_genesis_not_mistaken_for_nes_cart() {
        // "Sega Genesis" / "Genesis" both contain the substring "nes",
        // which would otherwise match the NES cart rule. The explicit
        // disc mappings above the cart block prevent that.
        XCTAssertEqual(MediaTypeInfer.infer(from: "Sega Genesis"),        .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Genesis"),             .disc)
        XCTAssertEqual(MediaTypeInfer.infer(from: "Mega Drive"),          .disc)
    }
}
