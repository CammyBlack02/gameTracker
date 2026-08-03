<?php

namespace GameTracker\Query;

/**
 * A compiled filter: the SQL fragment plus everything needed to run it.
 *
 * Immutable and transport-free. Services splice $whereSql into their own query
 * and bind $params; nothing here knows about the CLI or HTTP.
 */
final class FilterSet
{
    public function __construct(
        /** Conditions joined by AND, with no leading AND and no WHERE keyword. Empty when unfiltered. */
        public readonly string $whereSql,
        /** Positional parameters for $whereSql, in order. */
        public readonly array $params,
        /** Ready-to-splice ORDER BY body, e.g. "`created_at` DESC". */
        public readonly string $orderSql,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $offset,
    ) {
    }
}
