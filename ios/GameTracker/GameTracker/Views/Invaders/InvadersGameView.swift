import SwiftUI
import SpriteKit
import SwiftData
import AVFoundation

struct InvadersGameView: View {

    let games: [Game]
    let imagesAPI: ImagesAPI
    let onDismiss: () -> Void

    @State private var coordinator = Coordinator()
    @AppStorage("invadersBestScore") private var bestScore: Int = 0
    @Environment(\.scenePhase) private var scenePhase

    var body: some View {
        GeometryReader { geo in
            ZStack {
                Color(.systemBackground).ignoresSafeArea()
                if coordinator.isReady && !coordinator.loadFailed {
                    SpriteView(scene: coordinator.scene(for: geo.size, games: games))
                        .ignoresSafeArea()
                        .accessibilityHidden(true)
                    InvadersHUD(score: coordinator.score,
                                wave: coordinator.waveNumber,
                                bestScore: bestScore,
                                gameOver: coordinator.gameOver,
                                isNewBest: coordinator.finalScore > bestScore,
                                onPlayAgain: { coordinator.restart() },
                                onClose: onDismiss)
                } else {
                    loadingView
                }
            }
            .task {
                configureAudioSession()
                await coordinator.prewarmCovers(games: games, imagesAPI: imagesAPI)
            }
            .onChange(of: scenePhase) { _, phase in
                coordinator.setPaused(phase != .active)
            }
            .onChange(of: coordinator.finalScore) { _, newValue in
                if newValue > bestScore { bestScore = newValue }
            }
        }
    }

    private var loadingView: some View {
        VStack(spacing: 16) {
            if coordinator.loadFailed {
                Image(systemName: "photo.slash")
                    .font(.largeTitle)
                    .foregroundStyle(.secondary)
                Text("No covers loaded.\nPull to sync in Library first.")
                    .font(.callout.monospaced())
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
            } else {
                ProgressView()
                    .controlSize(.large)
                Text("Loading invaders…")
                    .font(.callout.monospaced())
                    .foregroundStyle(.secondary)
            }
            Button("Cancel", action: onDismiss)
                .buttonStyle(.bordered)
                .padding(.top, 8)
        }
    }

    /// Ambient category: the device mute switch silences our SFX
    /// and the user's existing audio (music / podcast) continues
    /// mixing alongside.
    private func configureAudioSession() {
        try? AVAudioSession.sharedInstance().setCategory(.ambient, mode: .default)
        try? AVAudioSession.sharedInstance().setActive(true)
    }

    // MARK: - Coordinator

    @Observable
    @MainActor
    final class Coordinator: InvadersSceneDelegate {

        private var _scene: InvadersScene?
        private var loader: CoverTextureLoader?
        private var sceneSize: CGSize = .zero

        /// Games whose covers were successfully pre-loaded. This is the pool
        /// passed to the scene — NOT the full original games array.
        private var loadedGames: [Game] = []
        /// Cover images keyed by persistent model ID, populated before the
        /// scene is created so wave 1 invaders have real covers immediately.
        private var preloadedCovers: [PersistentIdentifier: UIImage] = [:]

        /// True once all covers have been awaited and the scene is safe to create.
        var isReady: Bool = false
        /// True when preload completed but zero games had loadable covers.
        var loadFailed: Bool = false

        var score: Int = 0
        var waveNumber: Int = 1
        var gameOver: Bool = false
        var finalScore: Int = 0

        /// Only called after `isReady` is true. On the first call it creates and
        /// caches the scene; subsequent calls (e.g. on body re-eval) return the
        /// cached instance.
        func scene(for size: CGSize, games: [Game]) -> SKScene {
            self.sceneSize = size
            if let existing = _scene { return existing }
            return makeScene()
        }

        private func makeScene() -> InvadersScene {
            let scene = InvadersScene(size: sceneSize)
            scene.scaleMode = .resizeFill
            scene.configure(games: loadedGames)
            // Assign covers BEFORE the scene is presented so that
            // didMove(to:) → startNextWave() can use them immediately.
            scene.preloadedCovers = preloadedCovers
            scene.gameDelegate = self
            _scene = scene
            return scene
        }

        /// Awaits ALL covers up front. Once done, sets `isReady = true` so the
        /// View's body shows the SpriteView. Does NOT call `_scene?.applyCover`
        /// (no scene exists yet at this point).
        func prewarmCovers(games: [Game], imagesAPI: ImagesAPI) async {
            let loader = CoverTextureLoader(imagesAPI: imagesAPI)
            self.loader = loader

            var result: [PersistentIdentifier: UIImage] = [:]
            var loaded: [Game] = []

            for g in games.prefix(64).shuffled() {
                if let img = await loader.fetch(game: g) {
                    result[g.persistentModelID] = img
                    loaded.append(g)
                }
            }

            preloadedCovers = result
            loadedGames = loaded
            loadFailed = loaded.isEmpty
            isReady = true
        }

        func setPaused(_ paused: Bool) {
            _scene?.isPaused = paused
        }

        /// Rebuild the scene for a fresh run. `loadedGames` and `preloadedCovers`
        /// persist — no re-prewarm needed.
        func restart() {
            score = 0
            waveNumber = 1
            gameOver = false
            finalScore = 0
            // Capture the host SKView from the current scene before
            // makeScene() clobbers _scene with the new instance.
            let hostView = _scene?.view
            let fresh = makeScene()
            hostView?.presentScene(fresh)
        }

        // MARK: InvadersSceneDelegate

        func scoreDidChange(to score: Int) { self.score = score }
        func waveDidStart(_ wave: Int) { self.waveNumber = wave }
        func gameDidEnd(finalScore: Int) {
            self.finalScore = finalScore
            self.gameOver = true
        }
    }
}
