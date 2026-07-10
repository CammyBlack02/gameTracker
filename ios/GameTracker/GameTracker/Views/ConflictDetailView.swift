import SwiftUI
import SwiftData

/// Per-row conflict picker. Two choices:
///   "Keep phone"   → mark localModified, next push retries with local edits
///   "Keep server"  → apply the stored server_version to the local row,
///                    mark synced. The old implementation set
///                    `lastSyncedAt = nil` expecting a re-pull to bring
///                    the server row back, but pulls use the *global*
///                    cursor so a server row older than the cursor is
///                    never re-sent — the button did the opposite of its
///                    label. Now we apply the row we already have from
///                    the push conflict response. Fable §4 Bug 2 + 3.
struct ConflictDetailView: View {
    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss
    let route: ConflictRoute

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 16) {
                Text("This row was edited both on the phone and on the server.")
                    .foregroundStyle(.secondary)

                switch route {
                case .game(let id):
                    if let g: Game = self[id] { gameContent(g) }
                case .item(let id):
                    if let i: Item = self[id] { itemContent(i) }
                }
            }
            .padding()
        }
        .navigationTitle("Conflict")
    }

    // MARK: - Content

    @ViewBuilder
    private func gameContent(_ g: Game) -> some View {
        let server: GameDTO? = Self.decodeServerGame(g.serverVersionJSON)

        VStack(alignment: .leading, spacing: 8) {
            Label("Phone version", systemImage: "iphone").font(.headline)
            Text("Title: \(g.title)")
            Text("Platform: \(g.platform)")
            if let r = g.starRating { Text("Stars: \(r)") }
        }
        .padding()
        .background(Color.blue.opacity(0.1))
        .cornerRadius(8)

        if let s = server {
            VStack(alignment: .leading, spacing: 8) {
                Label("Server version", systemImage: "server.rack").font(.headline)
                Text("Title: \(s.title)")
                Text("Platform: \(s.platform)")
                if let r = s.starRating { Text("Stars: \(r)") }
            }
            .padding()
            .background(Color.green.opacity(0.1))
            .cornerRadius(8)
        } else {
            Text("(Server version unavailable — legacy conflict without stored server_version. \"Keep server\" will clear the conflict without applying data.)")
                .font(.caption)
                .foregroundStyle(.secondary)
        }

        Button("Keep phone version (retry push)") {
            g.syncState = .localModified
            g.serverVersionJSON = nil
            try? context.save()
            dismiss()
        }
        .buttonStyle(.borderedProminent)
        .frame(maxWidth: .infinity)

        Button("Keep server version (discard phone edits)") {
            ChangeApplier(context: context).applyStoredServerVersion(to: g)
            try? context.save()
            dismiss()
        }
        .buttonStyle(.bordered)
        .frame(maxWidth: .infinity)
    }

    @ViewBuilder
    private func itemContent(_ i: Item) -> some View {
        let server: ItemDTO? = Self.decodeServerItem(i.serverVersionJSON)

        VStack(alignment: .leading, spacing: 8) {
            Label("Phone version", systemImage: "iphone").font(.headline)
            Text("Title: \(i.title)")
            Text("Category: \(i.category)")
        }
        .padding()
        .background(Color.blue.opacity(0.1))
        .cornerRadius(8)

        if let s = server {
            VStack(alignment: .leading, spacing: 8) {
                Label("Server version", systemImage: "server.rack").font(.headline)
                Text("Title: \(s.title)")
                Text("Category: \(s.category)")
            }
            .padding()
            .background(Color.green.opacity(0.1))
            .cornerRadius(8)
        } else {
            Text("(Server version unavailable — legacy conflict without stored server_version. \"Keep server\" will clear the conflict without applying data.)")
                .font(.caption)
                .foregroundStyle(.secondary)
        }

        Button("Keep phone version (retry push)") {
            i.syncState = .localModified
            i.serverVersionJSON = nil
            try? context.save()
            dismiss()
        }
        .buttonStyle(.borderedProminent)
        .frame(maxWidth: .infinity)

        Button("Keep server version (discard phone edits)") {
            ChangeApplier(context: context).applyStoredServerVersion(to: i)
            try? context.save()
            dismiss()
        }
        .buttonStyle(.bordered)
        .frame(maxWidth: .infinity)
    }

    // MARK: - Lookup

    /// Subscript helper to resolve a PersistentIdentifier into its model.
    private subscript<T: PersistentModel>(_ id: PersistentIdentifier) -> T? {
        return context.model(for: id) as? T
    }

    // MARK: - Server-version decoding (display-only; resolution goes through ChangeApplier)

    private static func decodeServerGame(_ json: String?) -> GameDTO? {
        guard let json, let data = json.data(using: .utf8) else { return nil }
        return try? JSONDecoder().decode(GameDTO.self, from: data)
    }

    private static func decodeServerItem(_ json: String?) -> ItemDTO? {
        guard let json, let data = json.data(using: .utf8) else { return nil }
        return try? JSONDecoder().decode(ItemDTO.self, from: data)
    }
}
