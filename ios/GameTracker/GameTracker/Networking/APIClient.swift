import Foundation

/// Thin URLSession wrapper that:
///  - prepends the configured base URL,
///  - attaches the current Bearer token (if any),
///  - decodes `{"data": ...}` envelopes into typed responses,
///  - throws typed errors for non-2xx responses with a JSON error body.
///
/// All methods are `async throws`. The client is value-type-ish (final class
/// because URLSession isn't a value type) and safe to share across actors.
final class APIClient: @unchecked Sendable {

    private let session: URLSession
    private let baseURL: URL
    private let tokenProvider: @Sendable () -> String?
    private let decoder: JSONDecoder

    init(session: URLSession = .shared,
         baseURL: URL,
         tokenProvider: @escaping @Sendable () -> String? = { nil }) {
        self.session = session
        self.baseURL = baseURL
        self.tokenProvider = tokenProvider
        let d = JSONDecoder()
        d.dateDecodingStrategy = .iso8601WithFractional
        self.decoder = d
    }

    // MARK: - Public API

    func get<T: Decodable>(_ path: String,
                           query: [String: String] = [:],
                           timeout: TimeInterval? = nil) async throws -> T {
        var req = try buildRequest(method: "GET", path: path, query: query)
        if let timeout { req.timeoutInterval = timeout }
        return try await send(req)
    }

    func postForm<T: Decodable>(_ path: String,
                                fields: [String: String]) async throws -> T {
        var req = try buildRequest(method: "POST", path: path)
        let body = fields
            .map { "\(urlEncode($0.key))=\(urlEncode($0.value))" }
            .joined(separator: "&")
        req.httpBody = body.data(using: .utf8)
        req.setValue("application/x-www-form-urlencoded; charset=utf-8",
                     forHTTPHeaderField: "Content-Type")
        return try await send(req)
    }

    func postJSON<T: Decodable, B: Encodable>(_ path: String,
                                              body: B,
                                              timeout: TimeInterval? = nil) async throws -> T {
        var req = try buildRequest(method: "POST", path: path)
        let encoder = JSONEncoder()
        req.httpBody = try encoder.encode(body)
        req.setValue("application/json; charset=utf-8", forHTTPHeaderField: "Content-Type")
        if let timeout { req.timeoutInterval = timeout }
        return try await send(req)
    }

    /// Raw download (e.g., image bytes). Bypasses JSON decoding.
    func downloadData(_ path: String, query: [String: String] = [:]) async throws -> Data {
        let req = try buildRequest(method: "GET", path: path, query: query)
        let (data, response) = try await session.data(for: req)
        try Self.validateStatus(data: data, response: response)
        return data
    }

    /// Multipart upload (for cover upload). Single file field named "image".
    func uploadImage<T: Decodable>(_ path: String,
                                   query: [String: String] = [:],
                                   imageData: Data,
                                   filename: String,
                                   mimeType: String) async throws -> T {
        let boundary = "Boundary-\(UUID().uuidString)"
        var req = try buildRequest(method: "POST", path: path, query: query)
        req.setValue("multipart/form-data; boundary=\(boundary)",
                     forHTTPHeaderField: "Content-Type")
        var body = Data()
        body.append("--\(boundary)\r\n".data(using: .utf8)!)
        body.append("Content-Disposition: form-data; name=\"image\"; filename=\"\(filename)\"\r\n".data(using: .utf8)!)
        body.append("Content-Type: \(mimeType)\r\n\r\n".data(using: .utf8)!)
        body.append(imageData)
        body.append("\r\n--\(boundary)--\r\n".data(using: .utf8)!)
        req.httpBody = body
        return try await send(req)
    }

    // MARK: - Internals

    private func buildRequest(method: String,
                              path: String,
                              query: [String: String] = [:]) throws -> URLRequest {
        guard var comps = URLComponents(url: baseURL.appendingPathComponent(path),
                                        resolvingAgainstBaseURL: false) else {
            throw APIError.decoding("Could not build URLComponents from \(path)")
        }
        if !query.isEmpty {
            comps.queryItems = query.map { URLQueryItem(name: $0.key, value: $0.value) }
        }
        guard let url = comps.url else {
            throw APIError.decoding("Could not build URL from \(path)")
        }
        var req = URLRequest(url: url)
        req.httpMethod = method
        req.setValue("application/json", forHTTPHeaderField: "Accept")
        if let token = tokenProvider() {
            req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        }
        return req
    }

    private func send<T: Decodable>(_ req: URLRequest) async throws -> T {
        let (data, response): (Data, URLResponse)
        do {
            (data, response) = try await session.data(for: req)
        } catch let urlError as URLError {
            throw APIError.transport(urlError)
        }
        try Self.validateStatus(data: data, response: response)
        do {
            let envelope = try decoder.decode(APIEnvelope<T>.self, from: data)
            return envelope.data
        } catch {
            throw APIError.decoding(String(describing: error))
        }
    }

    private static func validateStatus(data: Data, response: URLResponse) throws {
        guard let http = response as? HTTPURLResponse else {
            throw APIError.unexpected(status: 0, bodyPrefix: "")
        }
        guard (200...299).contains(http.statusCode) else {
            // Try to decode the v2 error envelope.
            if let dto = try? JSONDecoder().decode(APIErrorDTO.self, from: data) {
                throw APIError.server(code: dto.error, message: dto.message, status: http.statusCode)
            }
            let prefix = String(data: data.prefix(256), encoding: .utf8) ?? ""
            throw APIError.unexpected(status: http.statusCode, bodyPrefix: prefix)
        }
    }

    private func urlEncode(_ s: String) -> String {
        var allowed = CharacterSet.urlQueryAllowed
        allowed.remove(charactersIn: "+&=")
        return s.addingPercentEncoding(withAllowedCharacters: allowed) ?? s
    }
}
