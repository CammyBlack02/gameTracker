<?php

namespace GameTracker\Services;

use PDO;

/**
 * Slim collection projection for 3D / shelf-style clients.
 *
 * A store view draws boxes. It needs a title, a platform, cover art and the
 * handful of fields that dress a case — it does not need `description`,
 * `review`, or the price columns, and shipping them costs real bytes when the
 * client pulls the WHOLE collection in one go (which a store must: you can't
 * page a room).
 *
 * Deliberately separate from GamesService::list rather than a flag on it:
 *
 *  - list()'s LIST_COLUMNS is pinned by v1's contract tests
 *    (tests/v2/test_v1_read_contract.sh) and shared with the CLI. Widening it
 *    to carry `release_date` for one consumer would push a column onto every
 *    caller.
 *  - list() pages; this deliberately does not. A store loads its whole stock
 *    once at boot and then renders offline.
 *
 * User-scoped like every other read: the caller's id is bound first and
 * unconditionally, so nothing here can widen past one user.
 *
 * @see docs/superpowers/specs/2026-08-09-3d-collection-store-design.md
 */
class StoreService
{
    /**
     * Hard ceiling on rows returned in one call. A store that needs more than
     * this has outgrown "load it all at boot" and wants a different design —
     * better to cap loudly than to stream 20k rows into a WebGL scene.
     */
    public const MAX_STOCK = 5000;

    private const STOCK_COLUMNS = '`id`, `title`, `platform`, `genre`, `series`,
                   `condition`, `star_rating`, `metacritic_rating`, `played`,
                   `is_physical`, `digital_store`, `front_cover_image`,
                   `back_cover_image`, `release_date`';

    /**
     * Every game the user owns, slimmed to what a shelf needs.
     *
     * @param string|null $platform Exact platform match, or null for all.
     * @return list<array>
     */
    public static function stock(PDO $pdo, int $userId, ?string $platform = null): array
    {
        $where = '`user_id` = ?';
        $params = [$userId];

        if ($platform !== null) {
            $where .= ' AND `platform` = ?';
            $params[] = $platform;
        }

        // Title order, article-insensitive collation left to the client: the
        // shelf comparator is a presentation concern and Halcyon has its own
        // (shelfTitleCompare). LIMIT is a constant, never input.
        $stmt = $pdo->prepare(
            'SELECT ' . self::STOCK_COLUMNS . ' FROM games
             WHERE ' . $where . '
             ORDER BY `title` ASC, `id` ASC
             LIMIT ' . self::MAX_STOCK
        );
        $stmt->execute($params);

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = self::normalise($row);
        }

        return $rows;
    }

    /**
     * Platform names with their game counts, for a client that builds one
     * aisle/section per platform.
     *
     * Derived from GamesService::platformCounts so the user scoping and the
     * blank-platform exclusion keep one definition.
     *
     * @return list<array{platform: string, games: int}>
     */
    public static function platforms(PDO $pdo, int $userId): array
    {
        return GamesService::platformCounts(
            $pdo,
            $userId,
            \GameTracker\Query\FilterSet::forSummary('', [], '`platform` ASC')
        );
    }

    /**
     * Stable numeric id for a platform NAME.
     *
     * Store clients index platforms by integer (Halcyon's RomM reader rejects
     * a platform whose `id` is not a number, and cross-checks every rom's
     * `platform_id` against the one it asked for). gameTracker keys platforms
     * by their string, so the two have to be bridged.
     *
     * CRC32 masked to 31 bits: deterministic, stateless, positive, and stable
     * across requests and restarts — no mapping table to persist or migrate,
     * and no dependence on how many platforms exist (an ordinal would
     * renumber every platform the moment a new one is added, silently moving
     * a client's shelves).
     *
     * Collisions are theoretically possible and practically irrelevant at
     * household scale (a few dozen platforms against a 2^31 space), but
     * resolvePlatform() below matches by recomputing over the user's own
     * platform list rather than trusting the number, so a collision would
     * mis-route rather than leak: both candidates belong to the same user.
     */
    public static function platformId(string $platform): int
    {
        return crc32($platform) & 0x7FFFFFFF;
    }

    /**
     * Map a numeric platform id back to the platform name, scoped to what
     * this user actually owns. Returns null when nothing matches.
     */
    public static function resolvePlatform(PDO $pdo, int $userId, int $platformId): ?string
    {
        foreach (self::platforms($pdo, $userId) as $row) {
            if (self::platformId($row['platform']) === $platformId) {
                return $row['platform'];
            }
        }

        return null;
    }

    /**
     * Turn a stored image column into a URL path.
     *
     * An image column holds a FILENAME or a URL, never an image (see
     * CLAUDE.md). External URLs pass through untouched; a bare filename
     * becomes the public uploads path. Returns null for empty.
     *
     * A `data:` URI is treated as absent rather than inlined: the store pulls
     * the entire collection at once, and a base64 payload per row is exactly
     * the ~113MB mistake the cover-image migration existed to undo.
     */
    public static function imagePath(?string $stored): ?string
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $stored)) {
            return $stored;
        }

        if (stripos($stored, 'data:') === 0) {
            return null;
        }

        return '/uploads/covers/' . ltrim($stored, '/');
    }

    private static function normalise(array $row): array
    {
        $year = null;
        if (!empty($row['release_date'])) {
            $parsed = substr((string)$row['release_date'], 0, 4);
            if (ctype_digit($parsed)) {
                $year = (int)$parsed;
            }
        }

        foreach (['genre', 'series', 'condition', 'digital_store'] as $field) {
            if (empty($row[$field])) {
                $row[$field] = null;
            }
        }

        return [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'platform' => (string)$row['platform'],
            'platform_id' => self::platformId((string)$row['platform']),
            'genre' => $row['genre'],
            'series' => $row['series'],
            'condition' => $row['condition'],
            'star_rating' => $row['star_rating'] !== null ? (int)$row['star_rating'] : null,
            'metacritic_rating' => $row['metacritic_rating'] !== null
                ? (int)$row['metacritic_rating']
                : null,
            'played' => (bool)$row['played'],
            'is_physical' => (bool)$row['is_physical'],
            'digital_store' => $row['digital_store'],
            'release_year' => $year,
            'front_cover' => self::imagePath($row['front_cover_image']),
            'back_cover' => self::imagePath($row['back_cover_image']),
        ];
    }
}
