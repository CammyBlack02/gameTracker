import SwiftUI
import SwiftData

@main
struct GameTrackerApp: App {
    let container: ModelContainer = {
        do {
            return try ModelContainerFactory.production()
        } catch {
            fatalError("Could not create SwiftData container: \(error)")
        }
    }()

    @State private var authManager = AuthManager()
    @State private var status = SyncStatus()

    @AppStorage("appearanceMode") private var appearanceMode: AppearanceMode = .system

    private var theme: Theme {
        ThemeRegistry.theme(for: appearanceMode)
    }

    private var apiClient: APIClient {
        APIClient(baseURL: Config.serverBaseURL,
                  tokenProvider: { [authManager] in authManager.currentToken })
    }

    private var authAPI: AuthAPI { AuthAPI(client: apiClient) }
    private var syncAPI: SyncAPI { SyncAPI(client: apiClient) }
    private var proxiesAPI: ProxiesAPI { ProxiesAPI(client: apiClient) }

    /// Cover cache lives under Documents/covers/ — backed up to iCloud per spec.
    private var imagesAPI: ImagesAPI {
        ImagesAPI(client: apiClient, cacheRoot: ImageCachePaths.coversThumbs)
    }

    var body: some Scene {
        WindowGroup {
            // Theme background + flourish are applied per-screen by
            // `.themedBackground()` on each tab / sheet root. We can't
            // host them at the WindowGroup level because SwiftUI's
            // TabView container is opaque and would cover them.
            RootViewContainer(authAPI: authAPI,
                              syncAPI: syncAPI,
                              proxiesAPI: proxiesAPI,
                              imagesAPI: imagesAPI,
                              status: status)
                .environment(authManager)
                .environment(\.theme, theme)
            .preferredColorScheme(theme.colorScheme)
            .tint(theme.accent)
            .fontDesign(theme.fontDesign)
            .onAppear {
                applyAppKitAppearance(for: theme, mode: appearanceMode)
            }
            .onChange(of: appearanceMode) { _, newMode in
                applyAppKitAppearance(for: ThemeRegistry.theme(for: newMode), mode: newMode)
            }
        }
        .modelContainer(container)
    }
}

/// Wraps RootView so we can grab the `modelContext` from the environment
/// (which is only injected *after* `.modelContainer(...)`) and build the
/// SyncEngine + SyncTrigger with it.
///
/// SyncEngine + SyncTrigger are held as `@State` so a @Bindable status
/// mutation doesn't rebuild them (which would drop debounced syncs and
/// reset `hasSyncedThisSession`, defeating "sync once per launch").
/// Fable §4 lifecycle smell.
private struct RootViewContainer: View {
    @Environment(\.modelContext) private var context
    let authAPI: AuthAPI
    let syncAPI: SyncAPI
    let proxiesAPI: ProxiesAPI
    let imagesAPI: ImagesAPI
    @Bindable var status: SyncStatus

    /// Lazily built on first appearance — `modelContext` isn't available
    /// at property-init time, so we can't populate these in an @State
    /// initializer. Once set, they persist across re-renders.
    @State private var engine: SyncEngine?
    @State private var trigger: SyncTrigger?

    var body: some View {
        Group {
            if let engine, let trigger {
                RootView(authAPI: authAPI,
                         syncEngine: engine,
                         syncTrigger: trigger,
                         imagesAPI: imagesAPI,
                         proxiesAPI: proxiesAPI,
                         status: status)
            } else {
                // First render: build the engines once, then re-render
                // with them populated. Setting @State inside the view
                // triggers exactly one re-evaluation.
                Color.clear
                    .task {
                        if engine == nil {
                            let e = SyncEngine(context: context, syncAPI: syncAPI, status: status)
                            engine = e
                            trigger = SyncTrigger(engine: e)
                        }
                    }
            }
        }
    }
}
