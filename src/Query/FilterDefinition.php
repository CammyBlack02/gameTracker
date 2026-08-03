<?php

namespace GameTracker\Query;

/**
 * Declares which filter flags a resource accepts and which columns they may
 * touch.
 *
 * Every column that reaches SQL comes from this object, never from user input,
 * which is what makes injection structurally impossible rather than merely
 * avoided. It is also why filters are per-resource: `gt items list --played`
 * should be a usage error, not a query against a column items does not have.
 */
final class FilterDefinition
{
    public function __construct(
        public readonly string $table,
        /** flag => column, matched with = */
        public readonly array $exact,
        /** flag => column, matched with LIKE %value% */
        public readonly array $like,
        /** flag => [column, bool] — true means "= 1", false means "= 0 OR IS NULL" */
        public readonly array $booleans,
        /** flag => [column, operator] */
        public readonly array $ranges,
        /** columns permitted as --missing=<column> */
        public readonly array $missingColumns,
        /** columns permitted as --sort=<column> */
        public readonly array $sortColumns,
        /** default sort, "-" prefix for descending */
        public readonly string $defaultSort,
    ) {
    }

    /**
     * Every flag this resource accepts, for the command's allowedOptions().
     * Derived rather than hand-listed, so adding a filter cannot forget to
     * allow it — the flag would otherwise be rejected as unknown.
     *
     * @return list<string>
     */
    public function flagNames(): array
    {
        return array_merge(
            array_keys($this->exact),
            array_keys($this->like),
            array_keys($this->booleans),
            array_keys($this->ranges),
            ['missing', 'sort', 'limit', 'page', 'per-page'],
        );
    }
}
