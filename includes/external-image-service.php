<?php
/**
 * Shared service: download an external image URL and save it under
 * uploads/covers/, optionally updating the games row for a given user.
 *
 * Callable from both the v1 (session-cookie) and v2 (Bearer-token)
 * endpoint layers so the v2 wrapper doesn't have to fake $_SESSION and
 * `require` the v1 file. Both endpoints go through this function.
 *
 * All external fetches use includes/http-fetch.php (Phase 1) so SSRF
 * gating happens here for free.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/thumbnail.php';
require_once __DIR__ . '/http-fetch.php';

/**
 * Result shape:
 *   Success:
 *     [
 *       'ok'       => true,
 *       'filename' => 'cover_1234567890.jpg',
 *       'url'      => '/uploads/covers/cover_1234567890.jpg',
 *       'game_id'  => 42 | null,
 *       'warning'  => 'optional message if the DB update failed after the file was saved',
 *     ]
 *   Failure:
 *     [
 *       'ok'      => false,
 *       'status'  => 400 | 404 | 500,
 *       'code'    => 'bad_request' | 'ssrf_blocked' | 'fetch_failed' | 'not_image' | 'save_failed' | 'not_found',
 *       'message' => 'human-readable',
 *     ]
 */
function gt_download_and_save_cover(
    PDO $pdo,
    int $userId,
    string $imageUrl,
    ?int $gameId,
    string $type
): array {
    if ($imageUrl === '') {
        return ['ok' => false, 'status' => 400, 'code' => 'bad_request', 'message' => 'URL is required'];
    }
    if (!in_array($type, ['front', 'back'], true)) {
        return ['ok' => false, 'status' => 400, 'code' => 'bad_request', 'message' => "type must be 'front' or 'back'"];
    }

    // If a game_id is supplied, enforce ownership up front. Previously v1
    // skipped this check; the v2 wrapper duplicated it inline. Now both
    // paths get it for free.
    if ($gameId !== null && $gameId > 0) {
        $ownStmt = $pdo->prepare("SELECT id FROM games WHERE id = ? AND user_id = ?");
        $ownStmt->execute([$gameId, $userId]);
        if (!$ownStmt->fetch()) {
            return ['ok' => false, 'status' => 404, 'code' => 'not_found', 'message' => 'Game not found'];
        }
    } else {
        $gameId = null;
    }

    // Fetch. SSRF gating + TLS verification handled by gt_safe_http_fetch.
    try {
        $fetch = gt_safe_http_fetch($imageUrl, [
            'accept' => 'image/jpeg,image/png,image/gif,image/webp,*/*',
        ]);
    } catch (GtSsrfException $e) {
        error_log("gt_download_and_save_cover SSRF blocked: {$e->getMessage()} for URL $imageUrl");
        return ['ok' => false, 'status' => 400, 'code' => 'ssrf_blocked', 'message' => 'URL not allowed'];
    } catch (GtFetchException $e) {
        error_log("gt_download_and_save_cover fetch failed: {$e->getMessage()} for URL $imageUrl");
        return ['ok' => false, 'status' => 500, 'code' => 'fetch_failed', 'message' => 'Failed to download image'];
    }

    $imageData   = $fetch['data'];
    $contentType = $fetch['content_type'];

    if (!gt_is_valid_image_data($imageData)) {
        return ['ok' => false, 'status' => 400, 'code' => 'not_image', 'message' => 'Downloaded data is not a valid image'];
    }

    // Determine extension. Prefer content-type; fall back to the URL path.
    $extension = 'jpg';
    if (stripos($contentType, 'png') !== false) {
        $extension = 'png';
    } elseif (stripos($contentType, 'gif') !== false) {
        $extension = 'gif';
    } elseif (stripos($contentType, 'webp') !== false) {
        $extension = 'webp';
    } else {
        $urlPath = parse_url($imageUrl, PHP_URL_PATH);
        $urlExtension = pathinfo((string)$urlPath, PATHINFO_EXTENSION);
        if (in_array(strtolower($urlExtension), ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            $extension = strtolower($urlExtension);
        }
    }

    $filename = generateUniqueFilename('cover_' . time() . '_' . uniqid() . '.' . $extension, COVERS_DIR);
    $targetPath = COVERS_DIR . $filename;

    if (!file_put_contents($targetPath, $imageData)) {
        error_log("gt_download_and_save_cover: failed to save image to $targetPath");
        return ['ok' => false, 'status' => 500, 'code' => 'save_failed', 'message' => 'Failed to save image'];
    }

    // Thumbnail generation is best-effort.
    gt_generate_thumbnail($targetPath, gt_thumbnail_path($targetPath), 512);

    $result = [
        'ok'       => true,
        'filename' => $filename,
        'url'      => '/uploads/covers/' . $filename,
        'game_id'  => $gameId,
    ];

    if ($gameId !== null) {
        $column = $type === 'back' ? 'back_cover_image' : 'front_cover_image';
        try {
            $updateStmt = $pdo->prepare("UPDATE games SET $column = ? WHERE id = ? AND user_id = ?");
            $updateStmt->execute([$filename, $gameId, $userId]);
        } catch (PDOException $e) {
            error_log("gt_download_and_save_cover: DB update failed for game $gameId: " . $e->getMessage());
            $result['warning'] = 'Image saved but database update failed';
        }
    }

    return $result;
}

/**
 * Magic-byte validation. Not perfect but keeps HTML / non-image data
 * from being written to disk with an image extension.
 */
function gt_is_valid_image_data(string $data): bool
{
    if ($data === '' || strlen($data) < 100) {
        return false;
    }
    $magic = bin2hex(substr($data, 0, 4));

    // JPEG: FF D8 FF
    if (substr($magic, 0, 6) === 'ffd8ff') return true;
    // PNG: 89 50 4E 47
    if ($magic === '89504e47') return true;
    // GIF: 47 49 46 38
    if (substr($magic, 0, 8) === '47494638') return true;
    // WebP: RIFF....WEBP
    if (substr($magic, 0, 8) === '52494646' && strpos($data, 'WEBP') !== false) return true;

    return false;
}
