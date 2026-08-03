<?php

namespace GameTracker\Services;

use GameTracker\Domain\AccessDeniedException;
use GameTracker\Domain\BadRequestException;
use GameTracker\Domain\NotFoundException;
use GameTracker\Query\FilterSet;
use PDO;

/**
 * Read paths for items (accessories). Same rules as GamesService: SELECT only,
 * explicit bound $userId, no transport concerns.
 */
final class ItemsService
{
    private const LIST_COLUMNS = '`id`, `title`, `platform`, `category`,
                   `description`, `condition`, `price_paid`,
                   `pricecharting_price`, `quantity`, `front_image`,
                   `back_image`, `notes`, `created_at`, `updated_at`';

    /**
     * @return array{items: list<array>, pagination: array}
     */
    public static function list(PDO $pdo, int $userId, FilterSet $filters): array
    {
        $where = '`user_id` = ?';
        $params = [$userId];

        if ($filters->whereSql !== '') {
            $where .= ' AND ' . $filters->whereSql;
            $params = array_merge($params, $filters->params);
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE {$where}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $totalPages = $total > 0 ? (int)ceil($total / $filters->perPage) : 1;

        $sql = 'SELECT ' . self::LIST_COLUMNS . '
                FROM items
                WHERE ' . $where . '
                ORDER BY ' . $filters->orderSql . '
                LIMIT ' . $filters->perPage . ' OFFSET ' . $filters->offset;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = self::normalise($row);
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $filters->page,
                'per_page' => $filters->perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'has_more' => $filters->page < $totalPages,
            ],
        ];
    }

    public static function get(PDO $pdo, int $userId, int $itemId, bool $isAdmin = false): array
    {
        if ($itemId <= 0) {
            throw new BadRequestException('Item ID is required');
        }

        $stmt = $pdo->prepare('SELECT * FROM items WHERE id = ?');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            throw new NotFoundException("No item with id {$itemId}");
        }

        if (!$isAdmin && (int)$item['user_id'] !== $userId) {
            throw new AccessDeniedException("Item {$itemId} belongs to another user");
        }

        $imagesStmt = $pdo->prepare(
            'SELECT * FROM item_images WHERE item_id = ? ORDER BY id DESC'
        );
        $imagesStmt->execute([$itemId]);
        $item['extra_images'] = $imagesStmt->fetchAll(PDO::FETCH_ASSOC);

        return self::normalise($item);
    }

    private static function normalise(array $row): array
    {
        $row['quantity'] = $row['quantity'] !== null ? (int)$row['quantity'] : null;

        return $row;
    }
}
