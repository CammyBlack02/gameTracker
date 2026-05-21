import XCTest
@testable import GameTracker

final class DebouncerTests: XCTestCase {

    func test_single_fire_runs_once_after_delay() async throws {
        let counter = ActorCounter()
        let debouncer = Debouncer(delay: 0.1) { await counter.bump() }

        await debouncer.fire()
        let initial = await counter.value
        XCTAssertEqual(initial, 0, "should not run immediately")

        try await Task.sleep(nanoseconds: 200_000_000)  // 200ms
        let after = await counter.value
        XCTAssertEqual(after, 1, "should have run exactly once")
    }

    func test_rapid_fires_collapse_to_one_run() async throws {
        let counter = ActorCounter()
        let debouncer = Debouncer(delay: 0.1) { await counter.bump() }

        for _ in 0..<5 {
            await debouncer.fire()
            try await Task.sleep(nanoseconds: 20_000_000)  // 20ms between fires
        }
        try await Task.sleep(nanoseconds: 250_000_000)  // wait past last delay
        let runs = await counter.value
        XCTAssertEqual(runs, 1, "5 rapid fires collapse to 1 run")
    }
}

private actor ActorCounter {
    var value = 0
    func bump() { value += 1 }
}
