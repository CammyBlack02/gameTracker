import XCTest
@testable import GameTracker

final class ProxiesAPITests: XCTestCase {

    override func setUp() {
        super.setUp()
        URLProtocolStub.reset()
    }

    func test_pricecharting_sends_title_and_platform_query() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"price":"42.50","title":"Halo"}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/pricecharting.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ProxiesAPI(client: client)
        let result = try await api.priceCharting(title: "Halo", platform: "Xbox")
        XCTAssertEqual(result["price"]?.stringValue, "42.50")

        let url = URLProtocolStub.recordedRequests.first!.url!
        let comps = URLComponents(url: url, resolvingAgainstBaseURL: false)!
        let qs = Dictionary(uniqueKeysWithValues: comps.queryItems!.map { ($0.name, $0.value!) })
        XCTAssertEqual(qs["title"], "Halo")
        XCTAssertEqual(qs["platform"], "Xbox")
    }

    func test_metacritic_returns_score() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"score":91}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/metacritic.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ProxiesAPI(client: client)
        let result = try await api.metacritic(title: "Halo", platform: "Xbox")
        XCTAssertEqual(result["score"]?.intValue, 91)
    }

    func test_externalImage_sends_url_and_game_id() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"path":"uploads/covers/abc.jpg"}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/external-image.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ProxiesAPI(client: client)
        let result = try await api.externalImage(url: "https://img.example/x.jpg",
                                                  gameId: 42,
                                                  face: .front)
        XCTAssertEqual(result["path"]?.stringValue, "uploads/covers/abc.jpg")

        let url = URLProtocolStub.recordedRequests.first!.url!
        let comps = URLComponents(url: url, resolvingAgainstBaseURL: false)!
        let qs = Dictionary(uniqueKeysWithValues: comps.queryItems!.map { ($0.name, $0.value!) })
        XCTAssertEqual(qs["url"], "https://img.example/x.jpg")
        XCTAssertEqual(qs["game_id"], "42")
        XCTAssertEqual(qs["type"], "front")
    }

    func test_uploadCover_sends_multipart_with_image_field() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"{"data":{"path":"uploads/covers/x.jpg","thumb_path":"uploads/covers/thumbs/x.jpg"}}"#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/games/cover-upload.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ProxiesAPI(client: client)
        let data = Data([0xFF, 0xD8, 0xFF, 0xE0, 0x00, 0x10])
        let result = try await api.uploadCover(gameId: 7, face: .back, imageData: data, filename: "x.jpg")
        XCTAssertEqual(result["path"]?.stringValue, "uploads/covers/x.jpg")

        let req = URLProtocolStub.recordedRequests.first!
        let ct = req.value(forHTTPHeaderField: "Content-Type") ?? ""
        XCTAssertTrue(ct.hasPrefix("multipart/form-data; boundary="))

        let comps = URLComponents(url: req.url!, resolvingAgainstBaseURL: false)!
        let qs = Dictionary(uniqueKeysWithValues: comps.queryItems!.map { ($0.name, $0.value!) })
        XCTAssertEqual(qs["game_id"], "7")
        XCTAssertEqual(qs["face"], "back")
    }
}
