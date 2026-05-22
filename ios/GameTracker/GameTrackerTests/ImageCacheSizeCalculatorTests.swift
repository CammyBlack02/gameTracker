import XCTest
@testable import GameTracker

final class ImageCacheSizeCalculatorTests: XCTestCase {

    private var tempDir: URL!

    override func setUpWithError() throws {
        tempDir = FileManager.default.temporaryDirectory
            .appendingPathComponent("ImageCacheSizeCalculatorTests-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: tempDir, withIntermediateDirectories: true)
    }

    override func tearDownWithError() throws {
        try? FileManager.default.removeItem(at: tempDir)
    }

    func test_empty_directory_returns_zero() {
        XCTAssertEqual(ImageCacheSizeCalculator.totalBytes(under: [tempDir]), 0)
    }

    func test_missing_directory_returns_zero() {
        let missing = tempDir.appendingPathComponent("does-not-exist")
        XCTAssertEqual(ImageCacheSizeCalculator.totalBytes(under: [missing]), 0)
    }

    func test_sums_file_sizes_in_single_root() throws {
        let a = tempDir.appendingPathComponent("a.bin")
        let b = tempDir.appendingPathComponent("b.bin")
        try Data(repeating: 0xAB, count: 1024).write(to: a)
        try Data(repeating: 0xCD, count: 2048).write(to: b)

        XCTAssertEqual(ImageCacheSizeCalculator.totalBytes(under: [tempDir]), 1024 + 2048)
    }

    func test_walks_subdirectories() throws {
        let sub = tempDir.appendingPathComponent("sub/deep")
        try FileManager.default.createDirectory(at: sub, withIntermediateDirectories: true)
        let f = sub.appendingPathComponent("file.bin")
        try Data(repeating: 0xEF, count: 512).write(to: f)

        XCTAssertEqual(ImageCacheSizeCalculator.totalBytes(under: [tempDir]), 512)
    }

    func test_sums_across_multiple_roots() throws {
        let other = FileManager.default.temporaryDirectory
            .appendingPathComponent("ImageCacheSizeCalculatorTests-other-\(UUID().uuidString)")
        try FileManager.default.createDirectory(at: other, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: other) }

        let a = tempDir.appendingPathComponent("a.bin")
        let b = other.appendingPathComponent("b.bin")
        try Data(repeating: 0xAB, count: 1024).write(to: a)
        try Data(repeating: 0xCD, count: 2048).write(to: b)

        XCTAssertEqual(ImageCacheSizeCalculator.totalBytes(under: [tempDir, other]), 1024 + 2048)
    }

    func test_formatted_returns_non_empty_string() {
        XCTAssertFalse(ImageCacheSizeCalculator.formatted(1_500_000).isEmpty)
        XCTAssertFalse(ImageCacheSizeCalculator.formatted(0).isEmpty)
    }
}
