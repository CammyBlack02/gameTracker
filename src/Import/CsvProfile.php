<?php

namespace GameTracker\Import;

use GameTracker\Cli\UsageException;

/**
 * A named mapping from CSV headers to database columns, plus the rule deciding
 * which table a record belongs in.
 *
 * The gameeye preset reproduces api/import-gameeye.php's routing exactly
 * (verified 2026-08-04). An unrecognised Category is skipped and counted rather
 * than guessed at: silently dropping records is how an import reports success
 * while losing data.
 */
final class CsvProfile
{
    private const PRESETS = ['gameeye'];

    private function __construct(
        public readonly string $name,
        /** database column => CSV header */
        public readonly array $columns,
        /** CSV Category values that are deliberately not imported */
        public readonly array $skippedCategories,
        /** Category => ['table' => …, 'extra' => [...]] */
        private readonly array $routes,
        private readonly ?string $routeColumn,
    ) {
    }

    /** @return list<string> */
    public static function names(): array
    {
        return self::PRESETS;
    }

    public static function named(string $name): self
    {
        if ($name !== 'gameeye') {
            throw new UsageException(
                "unknown CSV profile '{$name}'. Available: " . implode(', ', self::PRESETS)
                . ' — or describe your own with --map'
            );
        }

        return new self(
            name: 'gameeye',
            columns: [
                'title'               => 'Title',
                'platform'            => 'Platform',
                'condition'           => 'ItemCondition',
                'notes'               => 'Notes',
                'price_paid'          => 'PricePaid',
                'pricecharting_price' => 'PriceCIB',
            ],
            skippedCategories: ['Wishlist'],
            routes: [
                'Games'            => ['table' => 'games', 'extra' => []],
                'Systems'          => ['table' => 'items', 'extra' => ['category' => 'Console']],
                'Controllers'      => ['table' => 'items', 'extra' => ['category' => 'Accessory']],
                'Game Accessories' => ['table' => 'items', 'extra' => ['category' => 'Accessory']],
                'Toys To Life'     => ['table' => 'items', 'extra' => ['category' => 'Accessory']],
            ],
            routeColumn: 'Category',
        );
    }

    /**
     * A user-described mapping, e.g. --map=title:Name,platform:System.
     *
     * Everything lands in `games`: a hand-mapped CSV has no category vocabulary
     * to route on, and guessing would be worse than requiring the caller to
     * split the file.
     */
    public static function fromMap(array $map): self
    {
        if ($map === []) {
            throw new UsageException('--map is empty — expected <column>:<header> pairs');
        }

        return new self(
            name: 'custom',
            columns: $map,
            skippedCategories: [],
            routes: [],
            routeColumn: null,
        );
    }

    /**
     * Decide where a record goes.
     *
     * @return array{table: string, extra: array}|null null means skip
     */
    public function route(array $record): ?array
    {
        if ($this->routeColumn === null) {
            return ['table' => 'games', 'extra' => []];
        }

        $category = trim((string)($record[$this->routeColumn] ?? ''));

        if ($category === '' || in_array($category, $this->skippedCategories, true)) {
            return null;
        }

        return $this->routes[$category] ?? null;
    }
}
