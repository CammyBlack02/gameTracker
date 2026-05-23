import Foundation

/// Box shape for a game in CoverFlow.
enum MediaType {
    case disc      // DVD-case proportions
    case cart      // smaller Switch / 3DS / GBA-style case
}

/// Maps a user-entered platform string to a media type. Lives outside
/// the SwiftUI view tree so it's testable without mounting a scene.
enum MediaTypeInfer {

    /// Case-insensitive substring match against `cartKeywords`.
    /// Unknown / empty platforms default to `.disc` — that's the
    /// safer fallback because disc-format games make up most of the
    /// modern collection.
    static func infer(from platform: String) -> MediaType {
        let lower = platform.lowercased()
        for keyword in cartKeywords {
            if lower.contains(keyword.lowercased()) { return .cart }
        }
        return .disc
    }

    /// Substrings that indicate a cartridge-format game. Order
    /// doesn't matter — first match wins, all return `.cart`.
    private static let cartKeywords: [String] = [
        "nintendo switch",
        "switch",
        "3ds",
        "nintendo ds",
        "game boy",
        "gba",
        "snes",
        "super nintendo",
        "n64",
        "nintendo 64",
        "nes",         // also matches "SNES"; OK since both are carts
        "vita",
    ]
}
