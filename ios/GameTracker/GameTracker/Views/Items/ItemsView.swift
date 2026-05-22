import SwiftUI
import SwiftData

struct ItemsView: View {

    enum ViewMode: String, CaseIterable, Identifiable {
        case list, grid
        var id: String { rawValue }
        var systemImage: String { self == .list ? "list.bullet" : "square.grid.2x2" }
    }

    enum CategoryFilter: String, CaseIterable, Identifiable {
        case all, console, accessory
        var id: String { rawValue }
        var displayName: String {
            switch self {
            case .all:       return "All"
            case .console:   return "Consoles"
            case .accessory: return "Accessories"
            }
        }
    }

    let syncEngine: SyncEngine
    let syncTrigger: SyncTrigger
    let imagesAPI: ImagesAPI
    @Bindable var status: SyncStatus

    @Environment(\.modelContext) private var context

    @Query(filter: #Predicate<Item> { $0.syncStateRaw != "local_deleted" },
           sort: \Item.title,
           order: .forward)
    private var allItems: [Item]

    @State private var search = ""
    @State private var viewMode: ViewMode = .list
    @State private var categoryFilter: CategoryFilter = .all
    @State private var showAdd = false
    @State private var showConflicts = false

    private var filtered: [Item] {
        var rows = allItems
        switch categoryFilter {
        case .all:       break
        case .console:   rows = rows.filter { $0.category == "console" }
        case .accessory: rows = rows.filter { $0.category == "accessory" }
        }
        guard !search.isEmpty else { return rows }
        let s = search.lowercased()
        return rows.filter {
            $0.title.lowercased().contains(s)
            || ($0.platform?.lowercased().contains(s) ?? false)
        }
    }

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {
                ConflictBannerView(status: status) { showConflicts = true }
                SyncStatusBannerView(status: status)
                categoryPicker
                content
            }
            .navigationDestination(for: PersistentIdentifier.self) { id in
                ItemDetailView(itemID: id, imagesAPI: imagesAPI, syncTrigger: syncTrigger)
            }
            .navigationTitle("Items")
            .searchable(text: $search, prompt: "Search title or platform")
            .toolbar { toolbarContent }
            .sheet(isPresented: $showAdd) {
                AddItemView(syncTrigger: syncTrigger)
            }
            .sheet(isPresented: $showConflicts) { ConflictListView() }
            .task { try? await syncEngine.runOnce() }
            .refreshable { try? await syncEngine.runOnce() }
        }
    }

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItem(placement: .navigationBarTrailing) {
            Button { showAdd = true } label: { Image(systemName: "plus") }
        }
        ToolbarItem(placement: .navigationBarTrailing) {
            Menu {
                Picker("View", selection: $viewMode) {
                    ForEach(ViewMode.allCases) { mode in
                        Label(mode.rawValue.capitalized, systemImage: mode.systemImage).tag(mode)
                    }
                }
            } label: {
                Image(systemName: "ellipsis.circle")
            }
        }
    }

    private var categoryPicker: some View {
        Picker("Category", selection: $categoryFilter) {
            ForEach(CategoryFilter.allCases) { c in
                Text(c.displayName).tag(c)
            }
        }
        .pickerStyle(.segmented)
        .padding(.horizontal)
        .padding(.vertical, 8)
    }

    @ViewBuilder
    private var content: some View {
        let rows = filtered
        if rows.isEmpty {
            List {
                ContentUnavailableView("No items",
                                       systemImage: "shippingbox",
                                       description: Text("Pull to sync, or tap + to add a console or accessory."))
                    .listRowSeparator(.hidden)
                    .listRowBackground(Color.clear)
            }
            .listStyle(.plain)
        } else {
            switch viewMode {
            case .list:
                List {
                    ForEach(rows) { item in
                        NavigationLink(value: item.persistentModelID) {
                            ItemsListRow(item: item, imagesAPI: imagesAPI)
                        }
                    }
                    .onDelete(perform: delete(at:))
                }
                .listStyle(.plain)
            case .grid:
                ScrollView {
                    LazyVGrid(columns: [GridItem(.adaptive(minimum: 110), spacing: 12)],
                              spacing: 12) {
                        ForEach(rows) { item in
                            NavigationLink(value: item.persistentModelID) {
                                ItemsGridCell(item: item, imagesAPI: imagesAPI)
                                    .frame(height: 160)
                            }
                            .buttonStyle(.plain)
                        }
                    }
                    .padding(12)
                }
            }
        }
    }

    private func delete(at offsets: IndexSet) {
        let rows = filtered
        for i in offsets {
            let item = rows[i]
            if item.serverId == nil {
                context.delete(item)
            } else {
                item.syncState = .localDeleted
            }
        }
        try? context.save()
        syncTrigger.pingAfterMutation()
    }
}
