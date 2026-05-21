import XCTest
@testable import GameTracker

final class KeychainTokenStoreTests: XCTestCase {

    /// We use a dedicated test service name so the production keychain
    /// entry is never touched. Each test cleans up after itself.
    private let service = "com.cameron.GameTracker.tests"

    override func setUp() {
        super.setUp()
        try? KeychainTokenStore(service: service).delete()
    }

    override func tearDown() {
        try? KeychainTokenStore(service: service).delete()
        super.tearDown()
    }

    func test_save_and_load_round_trip() throws {
        let store = KeychainTokenStore(service: service)
        try store.save(token: "abc-123")
        XCTAssertEqual(try store.load(), "abc-123")
    }

    func test_load_returns_nil_when_unset() throws {
        let store = KeychainTokenStore(service: service)
        XCTAssertNil(try store.load())
    }

    func test_overwrite_existing_value() throws {
        let store = KeychainTokenStore(service: service)
        try store.save(token: "first")
        try store.save(token: "second")
        XCTAssertEqual(try store.load(), "second")
    }

    func test_delete_removes_value() throws {
        let store = KeychainTokenStore(service: service)
        try store.save(token: "to-be-deleted")
        try store.delete()
        XCTAssertNil(try store.load())
    }
}
