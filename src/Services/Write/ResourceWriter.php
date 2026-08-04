<?php

namespace GameTracker\Services\Write;

use GameTracker\Journal\JournalEntry;
use PDO;

/**
 * The undo entry point for one resource.
 *
 * UndoCommand resolves a journal entry's `resource` to an implementation and
 * hands the entry over. Which operations exist, and how each is reversed, is
 * knowledge that belongs to the writer that produced the entry — not to the
 * command, which would otherwise grow a switch over every resource-operation
 * pair as this sub-project expands.
 */
interface ResourceWriter
{
    /**
     * Reverse a committed journal entry.
     *
     * @return array{restored: int, skipped: int}
     */
    public static function revert(PDO $pdo, JournalEntry $entry, bool $force): array;
}
