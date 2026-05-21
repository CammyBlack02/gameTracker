import SwiftUI

struct ContentView: View {
    var body: some View {
        VStack(spacing: 12) {
            Text("gameTracker")
                .font(.largeTitle.weight(.bold))
            Text("Configured for: \(Config.serverBaseURL.host ?? "—")")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .padding()
    }
}

#Preview { ContentView() }
