import SwiftUI

struct InvadersHUD: View {

    let score: Int
    let wave: Int
    let bestScore: Int
    let gameOver: Bool
    let isNewBest: Bool
    let onPlayAgain: () -> Void
    let onClose: () -> Void

    var body: some View {
        ZStack {
            topBar
            if gameOver { gameOverPanel }
        }
        .animation(.easeInOut(duration: 0.3), value: gameOver)
    }

    private var topBar: some View {
        VStack {
            HStack(alignment: .top) {
                Button(action: onClose) {
                    Image(systemName: "xmark.circle.fill")
                        .font(.title2)
                        .symbolRenderingMode(.hierarchical)
                }
                .accessibilityLabel("Close")
                Spacer()
                VStack(alignment: .trailing, spacing: 2) {
                    Text("SCORE")
                        .font(.caption.bold().monospaced())
                        .foregroundStyle(.secondary)
                    Text("\(score)")
                        .font(.title2.bold().monospaced())
                }
            }
            HStack {
                Text("WAVE \(wave)  •  BEST \(bestScore)")
                    .font(.caption.bold().monospaced())
                    .foregroundStyle(.secondary)
                Spacer()
            }
            .padding(.top, 4)
            Spacer()
        }
        .padding(16)
    }

    private var gameOverPanel: some View {
        VStack(spacing: 12) {
            if isNewBest {
                Text("NEW BEST")
                    .font(.caption.bold().monospaced())
                    .foregroundStyle(.yellow)
            }
            Text("\(score)")
                .font(.system(size: 60, weight: .bold, design: .monospaced))
            Text("BEST  \(bestScore)")
                .font(.callout.monospaced())
                .foregroundStyle(.secondary)
            HStack(spacing: 16) {
                Button("Play Again", action: onPlayAgain)
                    .buttonStyle(.borderedProminent)
                Button("Close", action: onClose)
                    .buttonStyle(.bordered)
            }
            .padding(.top, 8)
        }
        .padding(24)
        .background(.regularMaterial, in: RoundedRectangle(cornerRadius: 16))
        .transition(.opacity.combined(with: .scale))
    }
}
