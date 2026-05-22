import SwiftUI

/// 5-tab bottom-bar shell. Only the Library tab is fully implemented in
/// Plan 3a; Items/Spin/Stats/Settings render placeholder screens.
struct RootTabView: View {
    let syncEngine: SyncEngine
    let syncTrigger: SyncTrigger
    let imagesAPI: ImagesAPI
    let proxiesAPI: ProxiesAPI
    let authAPI: AuthAPI
    @Bindable var status: SyncStatus

    var body: some View {
        TabView {
            LibraryView(syncEngine: syncEngine,
                        syncTrigger: syncTrigger,
                        imagesAPI: imagesAPI,
                        proxiesAPI: proxiesAPI,
                        status: status)
                .tabItem { Label("Library", systemImage: "books.vertical") }

            PlaceholderTabView(title: "Items",
                               systemImage: "gamecontroller",
                               blurb: "Consoles and accessories will live here.")
                .tabItem { Label("Items", systemImage: "gamecontroller") }

            CompletionsView(syncEngine: syncEngine,
                            syncTrigger: syncTrigger,
                            imagesAPI: imagesAPI,
                            status: status)
                .tabItem { Label("Completions", systemImage: "checkmark.seal") }

            PlaceholderTabView(title: "Stats",
                               systemImage: "chart.bar",
                               blurb: "Collection analytics.")
                .tabItem { Label("Stats", systemImage: "chart.bar") }

            SettingsView(authAPI: authAPI)
                .tabItem { Label("Settings", systemImage: "gear") }
        }
    }
}
