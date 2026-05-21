import XCTest
@testable import GameTracker

final class APIClientTests: XCTestCase {

    override func setUp() {
        super.setUp()
        URLProtocolStub.reset()
    }

    func test_get_sends_bearer_header_when_token_set() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"pong":true,"user_id":1}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))

        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "TEST_TOKEN" })
        struct Ping: Decodable { let pong: Bool; let userId: Int
            enum CodingKeys: String, CodingKey { case pong; case userId = "user_id" } }
        let result: Ping = try await client.get("/api/v2/_ping.php")

        XCTAssertTrue(result.pong)
        XCTAssertEqual(URLProtocolStub.recordedRequests.first?.value(forHTTPHeaderField: "Authorization"),
                       "Bearer TEST_TOKEN")
    }

    func test_get_omits_bearer_header_when_token_nil() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { nil })
        struct Empty: Decodable {}
        _ = try await client.get("/api/v2/auth/token.php") as Empty

        XCTAssertNil(URLProtocolStub.recordedRequests.first?.value(forHTTPHeaderField: "Authorization"))
    }

    func test_http_4xx_decodes_into_APIErrorDTO_thrown() async {
        URLProtocolStub.register(.init(
            statusCode: 401,
            body: #"{"error":"invalid_credentials","message":"Username or password is incorrect"}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { nil })
        struct Empty: Decodable {}
        do {
            _ = try await client.get("/api/v2/anything") as Empty
            XCTFail("should have thrown")
        } catch let err as APIError {
            guard case .server(let code, let message, let status) = err else {
                XCTFail("expected .server, got \(err)"); return
            }
            XCTAssertEqual(code, "invalid_credentials")
            XCTAssertEqual(message, "Username or password is incorrect")
            XCTAssertEqual(status, 401)
        } catch {
            XCTFail("expected APIError, got \(error)")
        }
    }

    func test_postForm_sends_url_encoded_body() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"token":"x","user_id":1,"username":"cam"}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { nil })
        let result: TokenResponseDTO = try await client.postForm(
            "/api/v2/auth/token.php",
            fields: ["username": "cam", "password": "secret"]
        )

        XCTAssertEqual(result.token, "x")
        let req = URLProtocolStub.recordedRequests.first!
        XCTAssertEqual(req.value(forHTTPHeaderField: "Content-Type"),
                       "application/x-www-form-urlencoded; charset=utf-8")
        let bodyData = req.httpBody ?? req.bodyStreamData() ?? Data()
        let bodyString = String(data: bodyData, encoding: .utf8) ?? ""
        XCTAssertTrue(bodyString.contains("username=cam"))
        XCTAssertTrue(bodyString.contains("password=secret"))
    }

    func test_postJSON_sends_json_body() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        struct Body: Encodable { let foo: String }
        struct Empty: Decodable {}
        _ = try await client.postJSON("/api/v2/x", body: Body(foo: "bar")) as Empty

        let req = URLProtocolStub.recordedRequests.first!
        XCTAssertEqual(req.value(forHTTPHeaderField: "Content-Type"), "application/json; charset=utf-8")
        let bodyData = req.httpBody ?? req.bodyStreamData() ?? Data()
        XCTAssertEqual(String(data: bodyData, encoding: .utf8), #"{"foo":"bar"}"#)
    }
}

private extension URLRequest {
    /// URLSession copies the body into an InputStream for any non-trivial
    /// request — read it out here so tests can assert on it.
    func bodyStreamData() -> Data? {
        guard let stream = httpBodyStream else { return nil }
        stream.open()
        defer { stream.close() }
        var data = Data()
        let buf = UnsafeMutablePointer<UInt8>.allocate(capacity: 4096)
        defer { buf.deallocate() }
        while stream.hasBytesAvailable {
            let n = stream.read(buf, maxLength: 4096)
            if n <= 0 { break }
            data.append(buf, count: n)
        }
        return data
    }
}
