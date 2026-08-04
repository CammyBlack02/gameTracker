<?php

namespace GameTracker\Import;

/**
 * Anything that can produce candidate rows.
 *
 * The only thing Importer knows about where rows came from. A source parses or
 * fetches; it never writes, which is why src/Import/ contains no write SQL and
 * the read-only guard stays meaningful.
 */
interface Source
{
    /**
     * @return iterable<ImportRow>
     */
    public function rows(): iterable;

    /** Human-readable description for the preview, e.g. "gameeye CSV (7 records)". */
    public function describe(): string;

    /**
     * Records the source deliberately did not emit — a Wishlist entry, an
     * unrecognised category, a row with no title.
     *
     * Only meaningful once rows() has been fully drained. On the interface
     * rather than probed for with method_exists, so a new source cannot
     * silently report zero skips by forgetting to implement it.
     */
    public function skipped(): int;
}
