<?php

namespace GameTracker\Services\Write;

use PDO;

/**
 * Downloads covers for freshly imported rows. Real implementation lands in the
 * next task; the signature is fixed here so the import commands can be built
 * and tested against it.
 */
final class CoverFetcher
{
    /**
     * @param list<array{table:string,id:int,coverUrl:?string}> $inserted
     * @return array{fetched:int, failed:int}
     */
    public static function fetchAll(PDO $pdo, int $userId, array $inserted): array
    {
        return ['fetched' => 0, 'failed' => 0];
    }
}
