import SwiftUI
import SwiftData

/// Lists every row currently in `syncState == .conflict`. Tapping opens
/// the per-row picker. v1 only handles `Game` and `Item` conflicts —
/// other tables fall through to "keep server" automatically.
struct ConflictListView: View {
    @Query(filter: #Predicate<Game> { $0.syncStateRaw == "conflict" })
    private var conflictGames: [Game]

    @Query(filter: #Predicate<Item> { $0.syncStateRaw == "conflict" })
    private var conflictItems: [Item]

    var body: some View {
        NavigationStack {
            List {
                if !conflictGames.isEmpty {
                    Section("Games") {
                        ForEach(conflictGames) { g in
                            NavigationLink(value: ConflictRoute.game(g.persistentModelID)) {
                                VStack(alignment: .leading) {
                                    Text(g.title).bold()
                                    Text(g.platform).font(.caption).foregroundStyle(.secondary)
                                }
                            }
                        }
                    }
                }
                if !conflictItems.isEmpty {
                    Section("Items") {
                        ForEach(conflictItems) { i in
                            NavigationLink(value: ConflictRoute.item(i.persistentModelID)) {
                                VStack(alignment: .leading) {
                                    Text(i.title).bold()
                                    Text(i.category).font(.caption).foregroundStyle(.secondary)
                                }
                            }
                        }
                    }
                }
                if conflictGames.isEmpty && conflictItems.isEmpty {
                    ContentUnavailableView("No conflicts", systemImage: "checkmark.seal")
                }
            }
            .navigationTitle("Resolve conflicts")
            .navigationDestination(for: ConflictRoute.self) { route in
                ConflictDetailView(route: route)
            }
        }
    }
}

enum ConflictRoute: Hashable {
    case game(PersistentIdentifier)
    case item(PersistentIdentifier)
}
