<?php
/**
 * Shared helpers for /api/v2/ endpoints.
 *
 * v2 uses a slightly different response shape than v1:
 *   success: { "data": {...} }
 *   error:   { "error": "code_slug", "message": "human readable" }
 *
 * Bearer tokens replace cookie sessions. Most endpoints call
 * v2_require_auth($pdo) at the top, which returns the authenticated
 * user's ID and exits with a 401 on failure.
 *
 * IMPORTANT — load order:
 *   This file must be required BEFORE includes/config.php. Its
 *   ini_set('display_errors', 0) must fire before config.php's PDO
 *   constructor, otherwise PHP 8.5's PDO::MYSQL_ATTR_INIT_COMMAND
 *   deprecation warning leaks into the JSON response body.
 *
 *   Endpoint include order:
 *     1. require_once __DIR__ . '/../_helpers.php';
 *     2. require_once __DIR__ . '/../../../includes/config.php';
 *     3. require_once __DIR__ . '/../../../includes/functions.php';  (if security logging is used)
 *     4. require_once __DIR__ . '/../_auth.php';
 *     5. $userId = v2_require_auth($pdo);
 */

// Suppress browser-friendly error output; we want clean JSON.
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

function v2_ok(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['data' => $data], JSON_UNESCAPED_SLASHES);
    exit;
}

function v2_error(string $code, string $message, int $status = 400): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $code, 'message' => $message], JSON_UNESCAPED_SLASHES);
    exit;
}

function v2_require_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        v2_error('method_not_allowed', "Use $method", 405);
    }
}

/**
 * Read JSON body into an associative array. Returns [] for empty body.
 */
function v2_json_body(): array {
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        v2_error('bad_request', 'Body must be a JSON object', 400);
    }
    return $decoded;
}
