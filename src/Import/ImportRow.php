<?php

namespace GameTracker\Import;

/**
 * One candidate row from a source, before anything has decided whether it is
 * new. Transport-free and SQL-free: a source produces these, and Importer is
 * the only thing that turns them into rows.
 */
final class ImportRow
{
    public function __construct(
        /** 'games' or 'items' */
        public readonly string $table,
        /** column => value, already using database column names */
        public readonly array $columns,
        /** Remote cover URL to fetch after the import commits, if any. */
        public readonly ?string $coverUrl = null,
    ) {
    }

    public function title(): string
    {
        return (string)($this->columns['title'] ?? '');
    }

    public function platform(): ?string
    {
        $platform = $this->columns['platform'] ?? null;

        return $platform === null ? null : (string)$platform;
    }
}
