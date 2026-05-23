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
        private var games: [Game] = []
        private var sceneSize: CGSize = .zero

        var score: Int = 0
        var waveNumber: Int = 1
        var gameOver: Bool = false
        var finalScore: Int = 0

        func scene(for size: CGSize, games: [Game]) -> SKScene {
            self.sceneSize = size
            if let existing = _scene { return existing }
            // Snapshot the games array on first call only — spec §6/§10:
            // sync writes mid-run must not change the active pool.
            self.games = games
            return makeScene()
        }

        private func makeScene() -> InvadersScene {
            let scene = InvadersScene(size: sceneSize)
            scene.scaleMode = .resizeFill
            scene.configure(games: games)
            scene.gameDelegate = self
            _scene = scene
            return scene
        }

        func prewarmCovers(games: [Game], imagesAPI: ImagesAPI) async {
            let loader = CoverTextureLoader(imagesAPI: imagesAPI)
            self.loader = loader
            for g in games.prefix(64).shuffled() {
                if let img = await loader.fetch(game: g) {
                    _scene?.applyCover(img, for: g.persistentModelID)
                }
            }
        }

        func setPaused(_ paused: Bool) {
            _scene?.isPaused = paused
        }

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
