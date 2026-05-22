import SwiftUI
import SwiftData

struct CompletionsView: View {

    let syncEngine: SyncEngine
    let syncTrigger: SyncTrigger
    let imagesAPI: ImagesAPI
    @Bindable var status: SyncStatus

    @Environment(\.modelContext) private var context

    @Query(filter: #Predicate<GameCompletion> { $0.syncStateRaw != "local_deleted" })
    private var allCompletions: [GameCompletion]

    @State private var search = ""
    @State private var showAdd = false
    @State private var showConflicts = false
    @State private var editingID: PersistentIdentifier?

    private var filtered: [GameCompletion] {
        let sorted = allCompletions.sorted { a, b in
            switch (a.dateCompleted, b.dateCompleted) {
            case let (l?, r?): return l > r
            case (_?, nil):    return true
            case (nil, _?):    return false
            case (nil, nil):   return a.createdAt > b.createdAt
            }
        }
        guard !search.isEmpty else { return sorted }
        let s = search.lowercased()
        return sorted.filter {
            $0.title.lowercased().contains(s)
            || ($0.notes?.lowercased().contains(s) ?? false)
        }
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                ConflictBannerView(status: status) { showConflicts = true }
                SyncStatusBannerView(status: status)
                content
            }
            .navigationTitle("Completions")
            .searchable(text: $search, prompt: "Search title or notes")
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    Button { showAdd = true } label: { Image(systemName: "plus") }
                }
            }
            .sheet(isPresented: $showAdd) {
                AddCompletionView(imagesAPI: imagesAPI, syncTrigger: syncTrigger)
            }
            .sheet(item: $editingID) { id in
                EditCompletionView(completionID: id,
                                   imagesAPI: imagesAPI,
                                   syncTrigger: syncTrigger)
            }
            .sheet(isPresented: $showConflicts) { ConflictListView() }
            .task { try? await syncEngine.runOnce() }
            .refreshable { try? await syncEngine.runOnce() }
        }
    }

    @ViewBuilder
    private var content: some View {
        let rows = filtered
        if rows.isEmpty {
            List {
                ContentUnavailableView("No completions",
                                       systemImage: "checkmark.seal",
                                       description: Text("Pull to sync, or tap + to log a finished game."))
                    .listRowSeparator(.hidden)
                    .listRowBackground(Color.clear)
            }
            .listStyle(.plain)
        } else {
            List {
                ForEach(rows) { c in
                    Button {
                        editingID = c.persistentModelID
                    } label: {
                        CompletionsListRow(completion: c, imagesAPI: imagesAPI)
                    }
                    .buttonStyle(.plain)
                }
                .onDelete(perform: delete(at:))
            }
            .listStyle(.plain)
        }
    }

    private func delete(at offsets: IndexSet) {
        let rows = filtered
        for i in offsets {
            let c = rows[i]
            if c.serverId == nil {
                context.delete(c)
            } else {
                c.syncState = .localDeleted
            }
        }
        try? context.save()
        syncTrigger.pingAfterMutation()
    }
}

// MARK: - PersistentIdentifier as an Identifiable sheet item

extension PersistentIdentifier: @retroactive Identifiable {
    public var id: Self { self }
}
