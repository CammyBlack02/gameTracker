import SwiftUI
import SwiftData

/// Per-row conflict picker. v1 offers two choices:
///   "Keep phone"   → mark localModified, push will retry
///   "Keep server"  → discard local edit, mark synced + nil lastSyncedAt
///                    so next /sync/changes pulls the server version
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
        VStack(alignment: .leading, spacing: 8) {
            Label("Phone version", systemImage: "iphone").font(.headline)
            Text("Title: \(g.title)")
            Text("Platform: \(g.platform)")
            if let r = g.starRating { Text("Stars: \(r)") }
        }
        .padding()
        .background(Color.blue.opacity(0.1))
        .cornerRadius(8)

        Button("Keep phone version (retry push)") {
            g.syncState = .localModified
            try? context.save()
            dismiss()
        }
        .buttonStyle(.borderedProminent)
        .frame(maxWidth: .infinity)

        Button("Keep server version (discard phone edits)") {
            g.syncState = .synced
            g.lastSyncedAt = nil
            try? context.save()
            dismiss()
        }
        .buttonStyle(.bordered)
        .frame(maxWidth: .infinity)
    }

    @ViewBuilder
    private func itemContent(_ i: Item) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Label("Phone version", systemImage: "iphone").font(.headline)
            Text("Title: \(i.title)")
            Text("Category: \(i.category)")
        }
        .padding()
        .background(Color.blue.opacity(0.1))
        .cornerRadius(8)

        Button("Keep phone version (retry push)") {
            i.syncState = .localModified
            try? context.save()
            dismiss()
        }
        .buttonStyle(.borderedProminent)
        .frame(maxWidth: .infinity)

        Button("Keep server version (discard phone edits)") {
            i.syncState = .synced
            i.lastSyncedAt = nil
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
}
