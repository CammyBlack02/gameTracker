import Foundation

/// Test-only URLProtocol that returns a fixed (status, body, headers) for any
/// URL matching `predicate`. Call `register(_:)` to install before constructing
/// a URLSession with `URLProtocolStub` in its configuration's `protocolClasses`.
///
/// IMPORTANT: This class uses static mutable state (`stubs`, `recordedRequests`).
/// Tests MUST be run serially — do not enable XCTest parallelization for any
/// suite using this stub. Each test should call `URLProtocolStub.reset()` in
/// its setUp to clear leftover state from the previous test.
final class URLProtocolStub: URLProtocol {

    struct Stub {
        let statusCode: Int
        let body: Data
        let headers: [String: String]
        let predicate: (URLRequest) -> Bool
    }

    static var stubs: [Stub] = []
    static var recordedRequests: [URLRequest] = []

    static func register(_ stub: Stub) { stubs.append(stub) }
    static func reset() { stubs.removeAll(); recordedRequests.removeAll() }

    /// Build a `URLSession` whose traffic this protocol intercepts.
    static func session() -> URLSession {
        let config = URLSessionConfiguration.ephemeral
        config.protocolClasses = [URLProtocolStub.self]
        return URLSession(configuration: config)
    }

    override class func canInit(with request: URLRequest) -> Bool { true }
    override class func canonicalRequest(for request: URLRequest) -> URLRequest { request }

    override func startLoading() {
        URLProtocolStub.recordedRequests.append(request)
        guard let stub = URLProtocolStub.stubs.first(where: { $0.predicate(request) }) else {
            client?.urlProtocol(self, didFailWithError: URLError(.unsupportedURL))
            return
        }
        let response = HTTPURLResponse(url: request.url!,
                                       statusCode: stub.statusCode,
                                       httpVersion: "HTTP/1.1",
                                       headerFields: stub.headers)!
        client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
        client?.urlProtocol(self, didLoad: stub.body)
        client?.urlProtocolDidFinishLoading(self)
    }

    override func stopLoading() {}
}
