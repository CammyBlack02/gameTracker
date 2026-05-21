import SwiftUI
import SwiftData

/// Temporary post-login landing screen. Lists all games (title only),
/// shows sync state, and exposes manual controls. Plan 3 replaces this
/// with the real five-tab interface.
struct DebugHomeView: View {
    @Environment(AuthManager.self) private var authManager
    @Environment(\.modelContext) private var context

    @Query(sort: \Game.createdAt, order: .reverse) private var games: [Game]

    let syncEngine: SyncEngine
    @Bindable var status: SyncStatus

    @State private var showConflicts = false

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                ConflictBannerView(status: status) { showConflicts = true }

                List {
                    Section("Sync") {
                        HStack {
                            Text("Status")
                            Spacer()
                            statusLabel
                        }
                        if let last = status.lastSyncedAt {
                            HStack {
                                Text("Last synced")
                                Spacer()
                                Text(last.formatted(date: .abbreviated, time: .standard))
                                    .foregroundStyle(.secondary)
                            }
                        }
                        Button("Sync now") {
                            Task { try? await syncEngine.runOnce() }
                        }
                        .disabled(status.phase == .syncing)
                    }

                    Section("Games (\(games.count))") {
                        if games.isEmpty {
                            Text("No games yet — sync to pull them down")
                                .foregroundStyle(.secondary)
                        }
                        ForEach(games) { g in
                            HStack {
                                Text(g.title)
                                Spacer()
                                syncStateBadge(g.syncState)
                            }
                        }
                    }

                    Section("Account") {
                        if case .loggedIn(let uid, let username) = authManager.state {
                            HStack {
                                Text("User")
                                Spacer()
                                Text("\(username) (#\(uid))").foregroundStyle(.secondary)
                            }
                        }
                        Button("Sign out", role: .destructive) {
                            Task { await signOut() }
                        }
                    }
                }
            }
            .navigationTitle("gameTracker")
            .sheet(isPresented: $showConflicts) { ConflictListView() }
            .task {
                try? await syncEngine.runOnce()
            }
            .refreshable {
                try? await syncEngine.runOnce()
            }
        }
    }

    private var statusLabel: some View {
        Group {
            switch status.phase {
            case .idle:               Text("Idle").foregroundStyle(.secondary)
            case .syncing:            HStack { ProgressView(); Text("Syncing…") }
            case .error(let message): Text(message).foregroundStyle(.red).lineLimit(2)
            }
        }
    }

    @ViewBuilder
    private func syncStateBadge(_ state: SyncState) -> some View {
        switch state {
        case .synced:        Image(systemName: "checkmark.circle").foregroundStyle(.secondary)
        case .localNew:      Text("new").font(.caption).foregroundStyle(.blue)
        case .localModified: Text("edit").font(.caption).foregroundStyle(.orange)
        case .localDeleted:  Text("del").font(.caption).foregroundStyle(.red)
        case .conflict:      Text("⚠ conflict").font(.caption).foregroundStyle(.red)
        }
    }

    private func signOut() async {
        authManager.clearLocalSession()
        do {
            try context.delete(model: Game.self)
            try context.delete(model: Item.self)
            try context.delete(model: GameCompletion.self)
            try context.delete(model: GameImage.self)
            try context.delete(model: ItemImage.self)
            try context.delete(model: SyncMetadata.self)
            try context.save()
        } catch {
            // Non-fatal; state will be inconsistent until next login wipe.
        }
    }
}
