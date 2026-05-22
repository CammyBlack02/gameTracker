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
                .themedBackground()
                .tabItem { Label("Library", systemImage: "books.vertical") }

            ItemsView(syncEngine: syncEngine,
                      syncTrigger: syncTrigger,
                      imagesAPI: imagesAPI,
                      status: status)
                .themedBackground()
                .tabItem { Label("Items", systemImage: "shippingbox") }

            CompletionsView(syncEngine: syncEngine,
                            syncTrigger: syncTrigger,
                            imagesAPI: imagesAPI,
                            status: status)
                .themedBackground()
                .tabItem { Label("Completions", systemImage: "checkmark.seal") }

            StatsView(syncEngine: syncEngine,
                      syncTrigger: syncTrigger,
                      imagesAPI: imagesAPI,
                      proxiesAPI: proxiesAPI,
                      status: status)
                .themedBackground()
                .tabItem { Label("Stats", systemImage: "chart.bar") }

            SettingsView(authAPI: authAPI, syncEngine: syncEngine, status: status)
                .themedBackground()
                .tabItem { Label("Settings", systemImage: "gear") }
        }
    }
}
