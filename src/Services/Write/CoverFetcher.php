<?php

namespace GameTracker\Services\Write;

use PDO;
use Throwable;

/**
 * Downloads covers for freshly imported rows.
 *
 * Runs only after the import transaction has committed. Doing this inside the
 * transaction would hold row locks through hundreds of HTTP requests — a
 * 300-game Steam import would keep the games table locked for minutes.
 *
 * Every failure is counted and swallowed. A cover is a nice-to-have; an
 * aborted import is not. gt_download_and_save_cover already handles SSRF
 * checks, magic-byte validation, the thumbnail, and the row update, so this
 * only has to decide what to do when it says no.
 */
final class CoverFetcher
{
    /**
     * @param list<array{table:string,id:int,coverUrl:?string}> $inserted
     * @return array{fetched:int, failed:int}
     */
    public static function fetchAll(PDO $pdo, int $userId, array $inserted): array
    {
        $fetched = 0;
        $failed = 0;

        require_once __DIR__ . '/../../../includes/external-image-service.php';

        foreach ($inserted as $row) {
            $url = $row['coverUrl'] ?? null;

            // Items have no cover column on this path, and a row without a URL
            // is not a failure — there was nothing to fetch.
            if ($url === null || $url === '' || ($row['table'] ?? '') !== 'games') {
                continue;
            }

            try {
                $result = gt_download_and_save_cover($pdo, $userId, $url, (int)$row['id'], 'front');
                if (($result['ok'] ?? false) === true) {
                    $fetched++;
                } else {
                    $failed++;
                }
            } catch (Throwable $e) {
                // Deliberately swallowed: see the class docblock.
                $failed++;
            }
        }

        return ['fetched' => $fetched, 'failed' => $failed];
    }
}
