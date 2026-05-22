import Foundation

/// Maps to `Item.category`. The web app stores the exact strings
/// `"Systems"`, `"Controllers"`, `"Game Accessories"`, or
/// `"Toys To Life"` (older rows may carry the legacy `"Console"`).
/// This enum's `rawValue` matches the web schema 1:1 so iOS-created
/// rows round-trip cleanly.
enum ItemCategory: String, CaseIterable, Identifiable {
    case systems         = "Systems"
    case controllers     = "Controllers"
    case gameAccessories = "Game Accessories"
    case toysToLife      = "Toys To Life"

    var id: String { rawValue }

    /// Display label in the form picker and detail header.
    var displayName: String { rawValue }

    /// SF Symbol shown next to the platform on rows and in the detail view.
    var systemImage: String {
        switch self {
        case .systems:         return "gamecontroller.fill"
        case .controllers:     return "gamecontroller"
        case .gameAccessories: return "cable.connector"
        case .toysToLife:      return "figure.stand"
        }
    }

    /// True if this category is the "console" half of the 2-way filter.
    /// Legacy rows whose raw string is `"Console"` are also treated as
    /// consoles (see `init(rawString:)`).
    var isConsole: Bool { self == .systems }

    /// Best-effort parse from a model string. Maps the legacy `"Console"`
    /// value (used on older rows) to `.systems` so the 2-way filter still
    /// groups it correctly. Unknown strings fall back to `.systems`.
    init(rawString: String?) {
        switch rawString {
        case "Systems":          self = .systems
        case "Controllers":      self = .controllers
        case "Game Accessories": self = .gameAccessories
        case "Toys To Life":     self = .toysToLife
        case "Console":          self = .systems
        default:                 self = .systems
        }
    }

    /// True when the raw string from the DB represents a console
    /// (including the legacy alias). Used by the 2-way category filter
    /// without forcing a fall-through `.systems` mapping for unknown values.
    static func isConsole(rawString: String?) -> Bool {
        rawString == "Systems" || rawString == "Console"
    }
}
