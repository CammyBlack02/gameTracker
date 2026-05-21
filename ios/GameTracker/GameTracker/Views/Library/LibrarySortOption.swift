import Foundation
import SwiftData

enum LibrarySortOption: String, CaseIterable, Identifiable {
    case titleAsc        = "Title (A→Z)"
    case titleDesc       = "Title (Z→A)"
    case recentlyAdded   = "Recently added"
    case recentlyUpdated = "Recently updated"

    var id: String { rawValue }

    /// SwiftData `SortDescriptor` for `Game`.
    var descriptor: SortDescriptor<Game> {
        switch self {
        case .titleAsc:        return SortDescriptor(\.title, order: .forward)
        case .titleDesc:       return SortDescriptor(\.title, order: .reverse)
        case .recentlyAdded:   return SortDescriptor(\.createdAt, order: .reverse)
        case .recentlyUpdated: return SortDescriptor(\.lastSyncedAt, order: .reverse)
        }
    }
}
