import SwiftUI

struct LoginView: View {
    @Environment(AuthManager.self) private var authManager
    @State private var username = ""
    @State private var password = ""
    @State private var isLoading = false
    @State private var errorMessage: String?

    /// Provided by parent so this view doesn't depend on knowing where
    /// APIClient is constructed.
    let authAPI: AuthAPI

    var body: some View {
        VStack(spacing: 20) {
            Text("gameTracker")
                .font(.largeTitle.weight(.bold))
                .padding(.top, 40)

            VStack(spacing: 12) {
                TextField("Username", text: $username)
                    .textContentType(.username)
                    .autocorrectionDisabled()
                    .textInputAutocapitalization(.never)
                    .textFieldStyle(.roundedBorder)

                SecureField("Password", text: $password)
                    .textContentType(.password)
                    .textFieldStyle(.roundedBorder)

                if let msg = errorMessage {
                    Text(msg)
                        .font(.callout)
                        .foregroundStyle(.red)
                        .multilineTextAlignment(.center)
                }

                Button(action: signIn) {
                    if isLoading {
                        ProgressView().tint(.white).frame(maxWidth: .infinity)
                    } else {
                        Text("Sign in").frame(maxWidth: .infinity)
                    }
                }
                .buttonStyle(.borderedProminent)
                .disabled(username.isEmpty || password.isEmpty || isLoading)
            }
            .padding(.horizontal)

            Spacer()
        }
        .padding()
    }

    private func signIn() {
        errorMessage = nil
        isLoading = true
        Task {
            do {
                let resp = try await authAPI.login(
                    username: username,
                    password: password,
                    deviceName: UIDevice.current.name
                )
                authManager.setLoggedIn(token: resp.token, userId: resp.userId, username: resp.username)
            } catch let err as APIError {
                errorMessage = err.errorDescription ?? "Sign-in failed."
            } catch {
                errorMessage = error.localizedDescription
            }
            isLoading = false
        }
    }
}
