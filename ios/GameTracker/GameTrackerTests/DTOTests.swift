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
}
