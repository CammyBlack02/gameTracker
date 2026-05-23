import SpriteKit
import SwiftData
import UIKit

// MARK: - Sound asset attributions
// All clips are CC0 (no attribution required; recorded here for
// traceability and future replacement).
// Source: Kenney (www.kenney.nl) — Creative Commons Zero (CC0)
// https://creativecommons.org/publicdomain/zero/1.0/
//
//   invaders_shoot.wav         — Kenney "Retro Sounds 1" / laser1.ogg
//                                https://kenney.nl/assets/retro-sounds-1
//   invaders_hit.wav           — Kenney "Retro Sounds 2" / hit1.ogg
//                                https://kenney.nl/assets/retro-sounds-2
//   invaders_invader_shoot.wav — Kenney "Retro Sounds 1" / laser4.ogg
//                                https://kenney.nl/assets/retro-sounds-1
//   invaders_death.wav         — Kenney "Retro Sounds 2" / lose1.ogg
//                                https://kenney.nl/assets/retro-sounds-2
// Converted from OGG to 44100 Hz / 16-bit mono WAV via macOS afconvert.

protocol InvadersSceneDelegate: AnyObject {
    func scoreDidChange(to score: Int)
    func waveDidStart(_ wave: Int)
    func gameDidEnd(finalScore: Int)
}

/// SpriteKit scene driving the Invaders mini-game. Owns the player,
/// the invader grid, in-flight bullets, and the per-frame update
/// loop. Scene origin is bottom-centre (`anchorPoint = (0.5, 0)`)
/// so positive Y is "up" and X is symmetrical around 0.
///
/// Sound files (`invaders_*.wav`) are looked up by name via
/// `SKAction.playSoundFileNamed` — they live in the app bundle and
/// are bundled by the synchronized Xcode group. If the file is
/// missing, SpriteKit logs a warning and continues silently.
final class InvadersScene: SKScene {

    // MARK: - Public surface

    weak var gameDelegate: InvadersSceneDelegate?

    private(set) var score: Int = 0 {
        didSet { gameDelegate?.scoreDidChange(to: score) }
    }
    private(set) var waveNumber: Int = 0

    func configure(games: [Game]) {
        self.games = games
    }

    /// Pre-loaded covers provided by the Coordinator before the scene starts.
    /// Set this before the scene is presented so `startNextWave` can use real
    /// covers on the very first wave.
    var preloadedCovers: [PersistentIdentifier: UIImage] = [:]

    /// Late-binding cover texture from `CoverTextureLoader`.
    /// Retained for defensive use; no longer called by the main Coordinator
    /// code path (covers are now fully preloaded before scene creation).
    func applyCover(_ image: UIImage, for gameID: PersistentIdentifier) {
        for invader in invaders where invader.gameID == gameID {
            invader.applyCover(image)
        }
    }

    // MARK: - State

    private var player: PlayerCannonNode!
    private var invaders: [InvaderNode] = []
    private var bullets: [BulletNode] = []
    private var games: [Game] = []
    private var isGameOver = false

    private var gridDirection: CGFloat = 1     // +1 right, -1 left
    private var currentSpeed: CGFloat = 30
    private var currentFireRate: CGFloat = 0.4
    private var lastFireTime: TimeInterval = 0
    private var lastUpdate: TimeInterval = 0

    /// IDs of games used in the most recent waves. Each new wave
    /// prefers games NOT in this set so consecutive waves don't
    /// recycle the same covers from a small loaded pool. Kept to a
    /// sliding window of roughly two waves' worth of slots.
    private var recentlyUsedIDs: [PersistentIdentifier] = []

    private let reduceMotion = UIAccessibility.isReduceMotionEnabled

    // MARK: - Lifecycle

    override func didMove(to view: SKView) {
        super.didMove(to: view)
        backgroundColor = .clear
        anchorPoint = CGPoint(x: 0.5, y: 0)

        player = PlayerCannonNode()
        player.position = CGPoint(x: 0, y: 60)
        addChild(player)

        startNextWave()
    }

    // MARK: - Touch handling

    override func touchesBegan(_ touches: Set<UITouch>, with event: UIEvent?) {
        moveTo(touch: touches.first)
    }

    override func touchesMoved(_ touches: Set<UITouch>, with event: UIEvent?) {
        moveTo(touch: touches.first)
    }

    private func moveTo(touch: UITouch?) {
        guard let touch, let view else { return }
        let p = touch.location(in: view)
        let scenePoint = convertPoint(fromView: p)
        let half = size.width / 2 - player.size.width / 2
        player.position.x = max(-half, min(half, scenePoint.x))
    }

    // MARK: - Game loop

    override func update(_ currentTime: TimeInterval) {
        guard !isGameOver else { return }
        if lastUpdate == 0 { lastUpdate = currentTime }
        // Cap dt so a foreground transition doesn't teleport the grid.
        let dt = min(currentTime - lastUpdate, 1.0 / 30)
        lastUpdate = currentTime
        guard dt > 0 else { return }

        advanceInvaderGrid(dt: dt)
        moveBullets(dt: dt)
        resolveCollisions()
        rollInvaderFire(dt: dt)
        rollPlayerAutoFire(now: currentTime)

        if invaders.isEmpty { startNextWave() }
    }

    private func advanceInvaderGrid(dt: TimeInterval) {
        guard !invaders.isEmpty else { return }
        let dx = currentSpeed * CGFloat(dt) * gridDirection
        for inv in invaders { inv.position.x += dx }

        let halfWidth = size.width / 2 - 20
        let maxX = invaders.map { $0.position.x + $0.size.width / 2 }.max() ?? 0
        let minX = invaders.map { $0.position.x - $0.size.width / 2 }.min() ?? 0

        if maxX > halfWidth || minX < -halfWidth {
            gridDirection *= -1
            let rowHeight: CGFloat = 40
            for inv in invaders { inv.position.y -= rowHeight }
        }
    }

    private func moveBullets(dt: TimeInterval) {
        for b in bullets {
            b.position.x += b.velocity.dx * CGFloat(dt)
            b.position.y += b.velocity.dy * CGFloat(dt)
        }
        let bounds = CGRect(x: -size.width / 2,
                             y: 0,
                             width: size.width,
                             height: size.height).insetBy(dx: -20, dy: -20)
        let offscreen = bullets.filter { !bounds.contains($0.position) }
        for b in offscreen {
            b.removeFromParent()
            bullets.removeAll { $0 === b }
        }
    }

    private func resolveCollisions() {
        var killedInvaders: [InvaderNode] = []
        var consumedBullets: [BulletNode] = []

        // Player bullets vs invaders
        for b in bullets where b.kind == .player {
            for inv in invaders where !killedInvaders.contains(where: { $0 === inv }) {
                if CollisionMath.rectsOverlap(b.frame, inv.frame) {
                    killedInvaders.append(inv)
                    consumedBullets.append(b)
                    score += inv.pointValue
                    run(SKAction.playSoundFileNamed("invaders_hit.wav",
                                                     waitForCompletion: false))
                    break
                }
            }
        }

        // Invader bullets vs player
        for b in bullets where b.kind == .invader {
            if CollisionMath.rectsOverlap(b.frame, player.frame) {
                consumedBullets.append(b)
                endGame()
                break
            }
        }

        // Invaders reaching the floor (player Y level)
        let playerTop = player.frame.maxY
        if !isGameOver && invaders.contains(where: { $0.frame.minY <= playerTop }) {
            endGame()
        }

        for inv in killedInvaders {
            inv.removeFromParent()
            invaders.removeAll { $0 === inv }
        }
        for b in consumedBullets {
            b.removeFromParent()
            bullets.removeAll { $0 === b }
        }
    }

    private func rollInvaderFire(dt: TimeInterval) {
        let probability = min(1, Double(currentFireRate) * dt)
        guard Double.random(in: 0..<1) < probability else { return }

        // Bucket invaders by approximate column (40pt buckets), pick
        // the lowest one in each column, then choose a random shooter
        // from that "front row" set.
        var lowestByColumn: [Int: InvaderNode] = [:]
        for inv in invaders {
            let bucket = Int((inv.position.x + 1000) / 40)
            if let existing = lowestByColumn[bucket] {
                if inv.position.y < existing.position.y {
                    lowestByColumn[bucket] = inv
                }
            } else {
                lowestByColumn[bucket] = inv
            }
        }
        guard let shooter = lowestByColumn.values.randomElement() else { return }

        let bullet = BulletNode(kind: .invader)
        bullet.position = CGPoint(x: shooter.position.x,
                                   y: shooter.frame.minY - 4)
        addChild(bullet)
        bullets.append(bullet)
        run(SKAction.playSoundFileNamed("invaders_invader_shoot.wav",
                                         waitForCompletion: false))
    }

    private func rollPlayerAutoFire(now: TimeInterval) {
        if now - lastFireTime < 0.35 { return }
        lastFireTime = now
        let bullet = BulletNode(kind: .player)
        bullet.position = CGPoint(x: player.position.x,
                                   y: player.frame.maxY + 4)
        addChild(bullet)
        bullets.append(bullet)
        run(SKAction.playSoundFileNamed("invaders_shoot.wav",
                                         waitForCompletion: false))
    }

    // MARK: - Wave start

    private func startNextWave() {
        waveNumber += 1
        let cfg = WaveGenerator.config(for: waveNumber)
        currentSpeed = reduceMotion ? cfg.speed * 0.5 : cfg.speed
        currentFireRate = cfg.fireRate
        gridDirection = 1

        let spacingX: CGFloat = 50
        let spacingY: CGFloat = 50
        let topY = size.height - 100
        let startX = -spacingX * CGFloat(cfg.cols - 1) / 2

        // Build a fresh pool for this wave. Two passes:
        //  1. games NOT used in the recent window (shuffled), then
        //  2. games that WERE recently used (shuffled), as fallback
        // This makes consecutive waves prefer fresh covers and only
        // recycle when the loaded library is smaller than two waves'
        // worth of slots. If the library is smaller than a single
        // wave, we cycle reshuffled copies for the remainder.
        let needed = cfg.rows * cfg.cols
        let recentSet = Set(recentlyUsedIDs)
        let fresh  = games.filter { !recentSet.contains($0.persistentModelID) }
        let recent = games.filter {  recentSet.contains($0.persistentModelID) }

        var pool: [Game] = []
        pool.append(contentsOf: fresh.shuffled())
        pool.append(contentsOf: recent.shuffled())
        while pool.count < needed {
            let nextShuffle = games.shuffled()
            if nextShuffle.isEmpty { break }
            pool.append(contentsOf: nextShuffle)
        }
        var poolIter = pool.makeIterator()
        var thisWaveIDs: [PersistentIdentifier] = []

        for r in 0..<cfg.rows {
            for c in 0..<cfg.cols {
                guard let game = poolIter.next() else { continue }
                thisWaveIDs.append(game.persistentModelID)
                let cover = preloadedCovers[game.persistentModelID]
                let invader = InvaderNode(gameID: game.persistentModelID,
                                          cover: cover,
                                          platformLabel: game.platform)
                invader.pointValue = 10 * waveNumber
                let finalPos = CGPoint(x: startX + CGFloat(c) * spacingX,
                                        y: topY - CGFloat(r) * spacingY)
                if reduceMotion {
                    invader.position = finalPos
                } else {
                    invader.position = CGPoint(x: finalPos.x, y: finalPos.y + 100)
                    invader.run(SKAction.move(to: finalPos, duration: 0.4))
                }
                addChild(invader)
                invaders.append(invader)
            }
        }

        // Slide the recency window forward — remember roughly two
        // waves' worth of IDs so the next wave can prefer fresh ones.
        recentlyUsedIDs.append(contentsOf: thisWaveIDs)
        let maxRecent = needed * 2
        if recentlyUsedIDs.count > maxRecent {
            recentlyUsedIDs.removeFirst(recentlyUsedIDs.count - maxRecent)
        }

        gameDelegate?.waveDidStart(waveNumber)
    }

    // MARK: - End

    private func endGame() {
        guard !isGameOver else { return }
        isGameOver = true
        run(SKAction.playSoundFileNamed("invaders_death.wav",
                                         waitForCompletion: false))
        gameDelegate?.gameDidEnd(finalScore: score)
        isPaused = true
    }
}
