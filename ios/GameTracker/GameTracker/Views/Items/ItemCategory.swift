import Foundation

/// Maps to `Item.category` (`"console"` / `"accessory"`). The model
/// stores a String so the value flows opaquely through sync; this
/// enum is purely a UI convenience.
enum ItemCategory: String, CaseIterable, Identifiable {
    case console
    case accessory

    var id: String { rawValue }

    var displayName: String {
        switch self {
        case .console:   return "Console"
        case .accessory: return "Accessory"
        }
    }

    /// SF Symbol shown next to the platform on rows and in the form.
    var systemImage: String {
        switch self {
        case .console:   return "gamecontroller.fill"
        case .accessory: return "cable.connector"
        }
    }

    /// Best-effort parse from a model string. Falls back to `.console`
    /// for any unrecognised value — defensive only; the web app
    /// enforces the two-value enum on its side.
    init(rawString: String?) {
        switch rawString {
        case "accessory": self = .accessory
        default:          self = .console
        }
    }
}
