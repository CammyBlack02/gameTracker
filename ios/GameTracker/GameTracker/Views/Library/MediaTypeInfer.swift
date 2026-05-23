import Foundation

/// Box shape for a game in CoverFlow.
enum MediaType {
    case disc      // DVD-case proportions (PS2+, Xbox, Wii, Switch, etc.)
    case cart      // chunky cartridge case (3DS, GBA, NES, SNES, N64)
    case jewel     // squarish CD-jewel case (PS1, Dreamcast, Saturn)
}

/// Maps a user-entered platform string to a media type. Lives outside
/// the SwiftUI view tree so it's testable without mounting a scene.
enum MediaTypeInfer {

    /// First-match wins against `mappings` (ordered most-specific first
    /// so e.g. "PlayStation 2" matches before bare "PlayStation").
    /// Unknown / empty platforms default to `.disc`.
    static func infer(from platform: String) -> MediaType {
        let lower = platform.lowercased()
        for (keyword, type) in mappings where lower.contains(keyword) {
            return type
        }
        return .disc
    }

    /// Ordered keyword table. Earlier rows win, so specific keywords
    /// must come before broader ones. Disc rules also need to come
    /// before cart rules where substring collisions exist — most
    /// notably "Sega Genesis" contains "nes" and must beat the NES
    /// cart mapping.
    private static let mappings: [(String, MediaType)] = [
        // === DISC (DVD-style) ===
        // PlayStation 2 and later.
        ("playstation 2", .disc),
        ("playstation 3", .disc),
        ("playstation 4", .disc),
        ("playstation 5", .disc),
        ("ps2", .disc),
        ("ps3", .disc),
        ("ps4", .disc),
        ("ps5", .disc),
        // PSP / PS Vita / Switch: smaller cases in real life but
        // rendered as standard disc cases per library convention.
        ("playstation vita", .disc),
        ("ps vita", .disc),
        ("psp", .disc),
        ("nintendo switch", .disc),
        ("switch", .disc),
        // Sega Genesis / Mega Drive — must beat the NES cart rule
        // because "genesis" contains "nes".
        ("sega genesis", .disc),
        ("genesis", .disc),
        ("mega drive", .disc),

        // === JEWEL (CD-jewel-style) ===
        ("playstation 1", .jewel),
        ("ps1", .jewel),
        ("psx", .jewel),
        ("dreamcast", .jewel),
        ("sega saturn", .jewel),
        ("saturn", .jewel),
        ("sega cd", .jewel),
        // Bare "PlayStation" → original PS1 by library convention.
        ("playstation", .jewel),

        // === CART (chunky cartridge case) ===
        // Note: SNES & N64 originally shipped in long horizontal
        // cardboard boxes; we approximate with the cart shape until
        // a dedicated `.longbox` is worth the code.
        ("3ds", .cart),
        ("nintendo ds", .cart),
        ("game boy", .cart),
        ("gba", .cart),
        ("snes", .cart),
        ("super nintendo", .cart),
        ("n64", .cart),
        ("nintendo 64", .cart),
        ("nes", .cart),
    ]
}
