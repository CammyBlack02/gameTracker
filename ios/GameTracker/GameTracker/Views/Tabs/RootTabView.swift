import SwiftUI

/// 5-tab bottom-bar shell. Only the Library tab is fully implemented in
/// Plan 3a; Items/Spin/Stats/Settings render placeholder screens.
struct RootTabView: View {
    let syncEngine: SyncEngine
    let syncTrigger: SyncTrigger
    let imagesAPI: ImagesAPI
    let proxiesAPI: ProxiesAPI
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

            PlaceholderTabView(title: "Spin",
                               systemImage: "dial.medium",
                               blurb: "Random game picker.")
                .tabItem { Label("Spin", systemImage: "dial.medium") }

            PlaceholderTabView(title: "Stats",
                               systemImage: "chart.bar",
                               blurb: "Collection analytics.")
                .tabItem { Label("Stats", systemImage: "chart.bar") }

            PlaceholderTabView(title: "Settings",
                               systemImage: "gear",
                               blurb: "Account, sync, appearance.")
                .tabItem { Label("Settings", systemImage: "gear") }
        }
    }
}
