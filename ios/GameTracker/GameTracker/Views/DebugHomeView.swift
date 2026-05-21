import SwiftUI

struct DebugHomeView: View {
    @Environment(AuthManager.self) private var authManager

    var body: some View {
        VStack(spacing: 16) {
            Text("Logged in").font(.title2.bold())
            if case .loggedIn(let uid, let username) = authManager.state {
                Text("\(username) (user \(uid))").foregroundStyle(.secondary)
            }
            Button("Sign out") {
                authManager.clearLocalSession()
            }
            .buttonStyle(.bordered)
        }
        .padding()
    }
}
