import XCTest
@testable import GameTracker

final class DTOTests: XCTestCase {

    private let decoder: JSONDecoder = {
        let d = JSONDecoder()
        d.dateDecodingStrategy = .iso8601WithFractional
        return d
    }()

    func test_decode_token_response() throws {
        let json = #"""
        { "data": { "token": "abc123", "user_id": 7, "username": "cam" } }
        """#.data(using: .utf8)!
        let env = try decoder.decode(APIEnvelope<TokenResponseDTO>.self, from: json)
        XCTAssertEqual(env.data.token, "abc123")
        XCTAssertEqual(env.data.userId, 7)
        XCTAssertEqual(env.data.username, "cam")
    }

    func test_decode_error_response() throws {
        let json = #"""
        { "error": "invalid_credentials", "message": "Username or password is incorrect" }
        """#.data(using: .utf8)!
        let err = try decoder.decode(APIErrorDTO.self, from: json)
        XCTAssertEqual(err.error, "invalid_credentials")
        XCTAssertEqual(err.message, "Username or password is incorrect")
    }

    func test_decode_changes_response_with_empty_arrays() throws {
        let json = #"""
        {
          "data": {
            "games": [], "items": [], "game_completions": [],
            "game_images": [], "item_images": [],
            "deletions": [],
            "server_now": "2026-05-21T10:30:00Z"
          }
        }
        """#.data(using: .utf8)!
        let env = try decoder.decode(APIEnvelope<ChangesResponseDTO>.self, from: json)
        XCTAssertEqual(env.data.games.count, 0)
        XCTAssertEqual(env.data.serverNow.timeIntervalSince1970, 1779359400, accuracy: 1)
    }

    func test_decode_push_response_with_mixed_results() throws {
        let json = #"""
        {
          "data": {
            "games": [
              { "client_id": "abc", "server_id": 1, "updated_at": "2026-05-21T10:30:00Z", "result": "accepted" },
              { "server_id": 2, "server_version": {"id": 2, "title": "S", "platform": "X"}, "result": "conflict" },
              { "server_id": 3, "result": "not_found" }
            ],
            "items": [], "game_completions": [], "game_images": [], "item_images": []
          }
        }
        """#.data(using: .utf8)!
        let env = try decoder.decode(APIEnvelope<PushResponseDTO>.self, from: json)
        XCTAssertEqual(env.data.games.count, 3)
        XCTAssertEqual(env.data.games[0].result, "accepted")
        XCTAssertEqual(env.data.games[1].result, "conflict")
        XCTAssertNotNil(env.data.games[1].serverVersion)
    }

    // MARK: - JSONValue discrimination

    func test_jsonValue_decodes_bool_not_int() throws {
        // JSON `true` must decode as .bool, NOT as .int(1).
        // Order in JSONValue.init matters: Bool must be tried before Int.
        struct Wrap: Decodable { let v: JSONValue }
        let trueData = #"{"v":true}"#.data(using: .utf8)!
        let falseData = #"{"v":false}"#.data(using: .utf8)!
        let trueDecoded = try JSONDecoder().decode(Wrap.self, from: trueData)
        let falseDecoded = try JSONDecoder().decode(Wrap.self, from: falseData)
        if case .bool(let b) = trueDecoded.v { XCTAssertTrue(b) } else { XCTFail("expected .bool(true), got \(trueDecoded.v)") }
        if case .bool(let b) = falseDecoded.v { XCTAssertFalse(b) } else { XCTFail("expected .bool(false), got \(falseDecoded.v)") }
    }

    func test_jsonValue_decodes_int_not_bool() throws {
        // JSON `1` must decode as .int(1), NOT as .bool(true).
        struct Wrap: Decodable { let v: JSONValue }
        let intData = #"{"v":1}"#.data(using: .utf8)!
        let decoded = try JSONDecoder().decode(Wrap.self, from: intData)
        if case .int(let i) = decoded.v { XCTAssertEqual(i, 1) } else { XCTFail("expected .int(1), got \(decoded.v)") }
    }

    func test_jsonValue_decodes_double_not_int() throws {
        // JSON `1.5` must decode as .double, not .int.
        struct Wrap: Decodable { let v: JSONValue }
        let data = #"{"v":1.5}"#.data(using: .utf8)!
        let decoded = try JSONDecoder().decode(Wrap.self, from: data)
        if case .double(let d) = decoded.v { XCTAssertEqual(d, 1.5, accuracy: 0.0001) } else { XCTFail("expected .double, got \(decoded.v)") }
    }

    func test_jsonValue_decodes_null() throws {
        struct Wrap: Decodable { let v: JSONValue }
        let data = #"{"v":null}"#.data(using: .utf8)!
        let decoded = try JSONDecoder().decode(Wrap.self, from: data)
        if case .null = decoded.v { /* ok */ } else { XCTFail("expected .null, got \(decoded.v)") }
    }

    // MARK: - Date strategy variants

    func test_dateStrategy_accepts_all_supported_formats() throws {
        let decoder = JSONDecoder()
        decoder.dateDecodingStrategy = .iso8601WithFractional
        // All four strings represent 2026-05-21T10:30:00 UTC.
        let variants = [
            "2026-05-21T10:30:00Z",
            "2026-05-21T10:30:00+00:00",
            "2026-05-21T10:30:00.000+00:00",
        ]
        let expected = TimeInterval(1779359400)
        for s in variants {
            struct Wrap: Decodable { let d: Date }
            let json = #"{"d":"\#(s)"}"#.data(using: .utf8)!
            let decoded = try decoder.decode(Wrap.self, from: json)
            XCTAssertEqual(decoded.d.timeIntervalSince1970, expected, accuracy: 1,
                           "format \(s) should decode to 2026-05-21T10:30:00Z")
        }
    }
}
