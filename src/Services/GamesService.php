<?php

namespace GameTracker\Services;

use GameTracker\Domain\AccessDeniedException;
use GameTracker\Domain\BadRequestException;
use GameTracker\Domain\NotFoundException;
use GameTracker\Query\FilterSet;
use PDO;

/**
 * Read paths for the games collection.
 *
 * Rules, per docs/superpowers/specs/2026-08-03-gt-cli-design.md:
 *
 *   - No $_GET / $_POST / $_SESSION / php://input. Input arrives as arguments.
 *   - No header() / echo / exit(). Results are returned, failures are thrown.
 *   - SELECT only. tests/cli/test_readonly_guard.sh enforces this.
 *   - Identity is an explicit int $userId, always bound, so the "every query is
 *     user-scoped" invariant from CLAUDE.md is visible in the signature.
 *
 * Row shaping matches what api/games.php produces, so a later sub-project can
 * swap that endpoint onto this service without the frontend noticing.
 */
final class GamesService
{
    /** Columns api/games.php's list action selected, in its order. */
    private const LIST_COLUMNS = '`id`, `title`, `platform`, `genre`, `series`,
                   `special_edition`, `condition`, `star_rating`,
                   `metacritic_rating`, `played`, `price_paid`,
                   `pricecharting_price`, `is_physical`, `digital_store`,
                   `front_cover_image`, `back_cover_image`, `created_at`,
                   `updated_at`';

    /**
     * @return array{games: list<array>, pagination: array}
     */
    public static function list(PDO $pdo, int $userId, FilterSet $filters): array
    {
        // The caller's id is bound first and unconditionally; filters can only
        // ever narrow the result, never widen it past this user.
        $where = '`user_id` = ?';
        $params = [$userId];

        if ($filters->whereSql !== '') {
            $where .= ' AND ' . $filters->whereSql;
            $params = array_merge($params, $filters->params);
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM games WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $totalPages = $total > 0 ? (int)ceil($total / $filters->perPage) : 1;

        // LIMIT/OFFSET cannot be bound in MySQL. Both are clamped ints from
        // FilterCompiler, never raw input.
        $sql = 'SELECT ' . self::LIST_COLUMNS . ', 0 AS extra_image_count
                FROM games
                WHERE ' . $where . '
                ORDER BY ' . $filters->orderSql . '
                LIMIT ' . $filters->perPage . ' OFFSET ' . $filters->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $games = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $games[] = self::normaliseListRow($row);
        }

        return [
            'games' => $games,
            'pagination' => [
                'page' => $filters->page,
                'per_page' => $filters->perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $filters->page < $totalPages,
            ],
        ];
    }

    /**
     * A single game with its extra images.
     *
     * $isAdmin reproduces the endpoint's admin override, which could read any
     * user's game. Passing it explicitly keeps that escalation visible at the
     * call site instead of being resolved in here from ambient state.
     */
    public static function get(PDO $pdo, int $userId, int $gameId, bool $isAdmin = false): array
    {
        if ($gameId <= 0) {
            throw new BadRequestException('Game ID is required');
        }

        $stmt = $pdo->prepare('SELECT * FROM games WHERE id = ?');
        $stmt->execute([$gameId]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$game) {
            throw new NotFoundException("No game with id {$gameId}");
        }

        // Kept separate from the lookup so "does not exist" and "not yours"
        // stay distinguishable, matching the endpoint's 404/403 split.
        if (!$isAdmin && (int)$game['user_id'] !== $userId) {
            throw new AccessDeniedException("Game {$gameId} belongs to another user");
        }

        $imagesStmt = $pdo->prepare(
            'SELECT * FROM game_images WHERE game_id = ? ORDER BY uploaded_at DESC'
        );
        $imagesStmt->execute([$gameId]);
        $game['extra_images'] = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

        return self::normaliseDetailRow($game);
    }

    /**
     * Distinct non-empty platform names for one user.
     *
     * Unlike the endpoint this has no global mode: the endpoint's unfiltered
     * branch returned every user's platforms for dropdown suggestions, which is
     * a web-form concern with no CLI equivalent. Scoped only, matching the
     * user-scoping invariant.
     *
     * @return list<string>
     */
    public static function platforms(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT DISTINCT `platform` FROM games
             WHERE `user_id` = ? AND `platform` IS NOT NULL AND `platform` != ''
             ORDER BY `platform`"
        );
        $stmt->execute([$userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private static function normaliseListRow(array $row): array
    {
        $row['played'] = (bool)$row['played'];
        $row['is_physical'] = (bool)$row['is_physical'];
        $row['star_rating'] = $row['star_rating'] !== null ? (int)$row['star_rating'] : null;
        $row['metacritic_rating'] = $row['metacritic_rating'] !== null
            ? (int)$row['metacritic_rating']
            : null;

        foreach (['genre', 'series', 'special_edition'] as $field) {
            if (empty($row[$field])) {
                $row[$field] = null;
            }
        }

        return $row;
    }

    /**
     * Narrower than the list variant on purpose: the endpoint's detail action
     * did not collapse empty genre/series/special_edition to null.
     */
    private static function normaliseDetailRow(array $row): array
    {
        $row['played'] = (bool)$row['played'];
        $row['is_physical'] = (bool)$row['is_physical'];
        $row['star_rating'] = $row['star_rating'] !== null ? (int)$row['star_rating'] : null;
        $row['metacritic_rating'] = $row['metacritic_rating'] !== null
            ? (int)$row['metacritic_rating']
            : null;

        return $row;
    }
}
