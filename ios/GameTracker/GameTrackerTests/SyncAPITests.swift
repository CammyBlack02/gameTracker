import XCTest
@testable import GameTracker

final class SyncAPITests: XCTestCase {

    override func setUp() {
        super.setUp()
        URLProtocolStub.reset()
    }

    func test_fetchChanges_sends_since_query_parameter() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{"games":[],"items":[],"game_completions":[],"game_images":[],"item_images":[],"deletions":[],"server_now":"2026-05-21T00:00:00Z"}}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/changes.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = SyncAPI(client: client)

        let since = Date(timeIntervalSince1970: 1747800000)
        _ = try await api.fetchChanges(since: since)

        let url = URLProtocolStub.recordedRequests.first!.url!
        let comps = URLComponents(url: url, resolvingAgainstBaseURL: false)!
        let sinceParam = comps.queryItems?.first(where: { $0.name == "since" })?.value
        XCTAssertNotNil(sinceParam)
        XCTAssertTrue(sinceParam!.hasSuffix("Z"), "ISO 8601 UTC, got \(sinceParam!)")
    }

    func test_fetchChanges_returns_parsed_dto() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{
              "games":[{"id":1,"title":"G","platform":"P","updated_at":"2026-05-21T00:00:00Z"}],
              "items":[],"game_completions":[],"game_images":[],"item_images":[],
              "deletions":[{"table_name":"games","server_id":99,"deleted_at":"2026-05-21T00:00:00Z"}],
              "server_now":"2026-05-21T00:00:00Z"
            }}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { _ in true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = SyncAPI(client: client)
        let result = try await api.fetchChanges(since: nil)

        XCTAssertEqual(result.games.count, 1)
        XCTAssertEqual(result.games[0].title, "G")
        XCTAssertEqual(result.deletions.count, 1)
        XCTAssertEqual(result.deletions[0].serverId, 99)
    }

    func test_push_sends_json_body_with_table_buckets() async throws {
        URLProtocolStub.register(.init(
            statusCode: 200,
            body: #"""
            {"data":{"games":[],"items":[],"game_completions":[],"game_images":[],"item_images":[]}}
            """#.data(using: .utf8)!,
            headers: ["Content-Type": "application/json"],
            predicate: { $0.url?.path.contains("/sync/push.php") == true }
        ))
        let client = APIClient(session: URLProtocolStub.session(),
                               baseURL: URL(string: "https://example.test")!,
                               tokenProvider: { "T" })
        let api = SyncAPI(client: client)

        let payload = PushPayload(
            games: PushBucket(new: [["client_id": .string("uuid-1"), "title": .string("X"), "platform": .string("Y")]],
                              modified: [], deleted: []),
            items: PushBucket.empty,
            gameCompletions: PushBucket.empty,
            gameImages: PushBucket.empty,
            itemImages: PushBucket.empty
        )
        _ = try await api.push(payload)

        let req = URLProtocolStub.recordedRequests.first!
        XCTAssertEqual(req.httpMethod, "POST")
        // Body may be in httpBody or httpBodyStream depending on URLSession.
        let bodyData = req.httpBody ?? {
            guard let stream = req.httpBodyStream else { return Data() }
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
        }()
        let str = String(data: bodyData, encoding: .utf8) ?? ""
        XCTAssertTrue(str.contains(#""games""#))
        XCTAssertTrue(str.contains(#""new""#))
        XCTAssertTrue(str.contains(#""title":"X""#))
    }
}
