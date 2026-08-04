<?php

namespace GameTracker\Query;

use GameTracker\Cli\UsageException;

/**
 * Turns parsed CLI options into a FilterSet.
 *
 * Column names are looked up in the FilterDefinition and backticked; values are
 * always bound. `condition` is a MySQL reserved word and a real column on both
 * games and items, which is why quoting is unconditional rather than
 * case-by-case.
 */
final class FilterCompiler
{
    public const DEFAULT_PER_PAGE = 100;
    public const MAX_PER_PAGE = 1000;

    public static function compile(FilterDefinition $def, OptionSource $ctx): FilterSet
    {
        $conditions = [];
        $params = [];

        foreach ($def->exact as $flag => $column) {
            $value = $ctx->option($flag);
            if ($value !== null) {
                $conditions[] = self::quote($column) . ' = ?';
                $params[] = $value;
            }
        }

        foreach ($def->like as $flag => $column) {
            $value = $ctx->option($flag);
            if ($value !== null) {
                $conditions[] = self::quote($column) . ' LIKE ?';
                $params[] = '%' . $value . '%';
            }
        }

        foreach ($def->booleans as $flag => [$column, $wanted]) {
            if (!$ctx->flag($flag)) {
                continue;
            }
            // played and is_physical are nullable ints, so "false" has to mean
            // "0 or never set". Matching only 0 would make rows whose value was
            // never set vanish from both --played and --unplayed, which reads as
            // data loss to whoever ran the query.
            $conditions[] = $wanted
                ? self::quote($column) . ' = 1'
                : '(' . self::quote($column) . ' = 0 OR ' . self::quote($column) . ' IS NULL)';
        }

        foreach ($def->ranges as $flag => [$column, $operator]) {
            $value = $ctx->option($flag);
            if ($value !== null) {
                $conditions[] = self::quote($column) . ' ' . $operator . ' ?';
                $params[] = $value;
            }
        }

        $missing = $ctx->option('missing');
        if ($missing !== null) {
            if (!in_array($missing, $def->missingColumns, true)) {
                throw new UsageException(
                    '--missing=' . $missing . " is not a filterable column on {$def->table}. "
                    . 'Available: ' . implode(', ', $def->missingColumns)
                );
            }
            $conditions[] = '(' . self::quote($missing) . ' IS NULL OR ' . self::quote($missing) . " = '')";
        }

        [$page, $perPage, $offset] = self::paging($ctx);

        return new FilterSet(
            implode(' AND ', $conditions),
            $params,
            self::orderSql($def, $ctx),
            $page,
            $perPage,
            $offset
        );
    }

    private static function orderSql(FilterDefinition $def, OptionSource $ctx): string
    {
        $raw = $ctx->option('sort') ?? $def->defaultSort;

        $descending = str_starts_with($raw, '-');
        $column = $descending ? substr($raw, 1) : $raw;

        if (!in_array($column, $def->sortColumns, true)) {
            throw new UsageException(
                "--sort={$raw} is not a sortable column on {$def->table}. "
                . 'Available: ' . implode(', ', $def->sortColumns)
            );
        }

        return self::quote($column) . ($descending ? ' DESC' : ' ASC');
    }

    /**
     * @return array{0: int, 1: int, 2: int} page, perPage, offset
     */
    private static function paging(OptionSource $ctx): array
    {
        // --limit is the interactive shorthand for --per-page.
        $perPage = $ctx->intOption('limit', $ctx->intOption('per-page', self::DEFAULT_PER_PAGE));
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $ctx->intOption('page', 1));

        return [$page, $perPage, ($page - 1) * $perPage];
    }

    private static function quote(string $column): string
    {
        return '`' . $column . '`';
    }
}
