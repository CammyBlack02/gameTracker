import XCTest
@testable import GameTracker

final class AuthAPITests: XCTestCase {

    override func setUp() {
        super.setUp()
        URLProtocolStub.reset()
    }

    func test_login_posts_credentials_and_returns_token() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"token":"abc","user_id":7,"username":"cam"}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/auth/token.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!)
        let auth = AuthAPI(client: client)

        let response = try await auth.login(username: "cam", password: "secret", deviceName: "iPhone 15")
        XCTAssertEqual(response.token, "abc")
        XCTAssertEqual(response.userId, 7)
    }

    func test_login_wrong_password_throws_server_error() async {
        URLProtocolStub.register(.init(
            statusCode: 401,
            body: #"{"error":"invalid_credentials","message":"..."}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!)
        let auth = AuthAPI(client: client)
        do {
            _ = try await auth.login(username: "cam", password: "x", deviceName: nil)
            XCTFail("should have thrown")
        } catch let err as APIError {
            guard case .server(let code, _, _) = err else { XCTFail(); return }
            XCTAssertEqual(code, "invalid_credentials")
        } catch {
            XCTFail("expected APIError, got \(error)")
        }
    }

    func test_revoke_posts_with_bearer_header() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"revoked":true}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/auth/revoke.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "MY_TOKEN" })
        let auth = AuthAPI(client: client)
        let response = try await auth.revoke()

        XCTAssertTrue(response.revoked)
        XCTAssertEqual(URLProtocolStub.recordedRequests.first?.value(forHTTPHeaderField: "Authorization"),
                       "Bearer MY_TOKEN")
    }
}
