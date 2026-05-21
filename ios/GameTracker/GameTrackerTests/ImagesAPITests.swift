import XCTest
@testable import GameTracker

final class ImagesAPITests: XCTestCase {

    private var tempCacheDir: URL!

    override func setUp() {
        super.setUp()
        URLProtocolStub.reset()
        tempCacheDir = FileManager.default.temporaryDirectory
            .appendingPathComponent("imagecache-\(UUID().uuidString)")
        try? FileManager.default.createDirectory(at: tempCacheDir, withIntermediateDirectories: true)
    }

    override func tearDown() {
        try? FileManager.default.removeItem(at: tempCacheDir)
        super.tearDown()
    }

    func test_downloadCover_writes_bytes_to_cache_dir() async throws {
        let payload = Data([0xFF, 0xD8, 0xFF, 0xE0])  // JPEG header bytes
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: payload,
            headers: ["Content-Type": "image/jpeg"],
            predicate: { $0.url?.path.contains("/images/cover.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ImagesAPI(client: client, cacheRoot: tempCacheDir)

        let cached = try await api.downloadCover(gameServerId: 42, face: .front, size: .thumb)
        XCTAssertTrue(FileManager.default.fileExists(atPath: cached.path))
        let bytes = try Data(contentsOf: cached)
        XCTAssertEqual(bytes, payload)
    }

    func test_downloadCover_second_call_returns_cached_file_without_network() async throws {
        let payload = Data([0xFF, 0xD8, 0xFF, 0xE0])
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: payload,
            headers: ["Content-Type": "image/jpeg"],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ImagesAPI(client: client, cacheRoot: tempCacheDir)

        _ = try await api.downloadCover(gameServerId: 42, face: .front, size: .thumb)
        _ = try await api.downloadCover(gameServerId: 42, face: .front, size: .thumb)

        XCTAssertEqual(URLProtocolStub.recordedRequests.count, 1,
                       "second call should hit cache, not network")
    }

    func test_downloadCover_builds_correct_query_string() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: Data([0xFF]),
            headers: [:],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = ImagesAPI(client: client, cacheRoot: tempCacheDir)
        _ = try await api.downloadCover(gameServerId: 7, face: .back, size: .full)

        let url = URLProtocolStub.recordedRequests.first!.url!
        let comps = URLComponents(url: url, resolvingAgainstBaseURL: false)!
        let qs = Dictionary(uniqueKeysWithValues: comps.queryItems!.map { ($0.name, $0.value!) })
        XCTAssertEqual(qs["id"], "7")
        XCTAssertEqual(qs["face"], "back")
        XCTAssertEqual(qs["size"], "full")
    }
}
