<?php

namespace GameTracker\Cli\Commands;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UserResolver;
use GameTracker\Domain\NotFoundException;
use GameTracker\Journal\JournalEntry;
use GameTracker\Journal\JournalWriter;
use GameTracker\Domain\BadRequestException;
use GameTracker\Services\Write\GamesWriter;
use GameTracker\Services\Write\ItemsWriter;
use GameTracker\Services\Write\ResourceWriter;

/**
 * Reverts a journalled write.
 *
 * Inherits the same confirmation rule as every other write rather than
 * special-casing itself: reverting one row applies immediately, reverting
 * several previews first. Undoing a 202-row bulk edit is a 202-row write.
 */
final class UndoCommand implements Command
{
    public const NAME = 'undo';

    /**
     * Resource name as journalled => the writer that knows how to reverse it.
     *
     * @var array<string, class-string<ResourceWriter>>
     */
    private const REVERTERS = [
        'games' => GamesWriter::class,
        'items' => ItemsWriter::class,
    ];

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Revert a journalled write (see --list)';
    }

    public static function allowedOptions(): array
    {
        return ['list', 'yes', 'force'];
    }

    public function run(array $args, Context $ctx): int
    {
        $journal = new JournalWriter();

        if ($ctx->flag('list')) {
            return $this->list($ctx, $journal);
        }

        $id = $args[0] ?? null;

        $entry = $id !== null
            ? $journal->read($id)
            : $journal->latestRevertable();

        if ($entry === null) {
            throw new NotFoundException(
                'nothing to undo — no committed, unreverted journal entry found'
            );
        }

        if (!$entry->isRevertable()) {
            throw new NotFoundException(
                "nothing to undo for {$entry->id} — it is "
                . ($entry->revertedAt !== null ? 'already reverted' : 'not committed')
            );
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);

        if ((int)$user['id'] !== $entry->userId) {
            throw new NotFoundException(
                "journal entry {$entry->id} belongs to a different user"
            );
        }

        $rowCount = count($entry->rows);

        if ($rowCount > 1 && !$ctx->flag('yes')) {
            return $this->preview($ctx, $entry, $rowCount);
        }

        $writer = self::REVERTERS[$entry->resource] ?? null;

        if ($writer === null) {
            throw new BadRequestException(
                "cannot revert '{$entry->resource}' — no writer is registered for it"
            );
        }

        $result = $writer::revert($ctx->pdo, $entry, $ctx->flag('force'));

        // Only mark it reverted if something actually was. Otherwise a refusal
        // would consume the entry and leave no way to retry with --force.
        if ($result['restored'] > 0) {
            $journal->markReverted($entry->id);
        }

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line(sprintf(
                'restored %d, skipped %d',
                $result['restored'],
                $result['skipped']
            ));
            if ($result['skipped'] > 0) {
                $ctx->output->warn(
                    'skipped rows changed since the write — re-run with --force to overwrite them'
                );
            }

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'journal_id' => $entry->id,
            'restored' => $result['restored'],
            'skipped' => $result['skipped'],
        ]);

        return 0;
    }

    private function preview(Context $ctx, JournalEntry $entry, int $rowCount): int
    {
        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line("would restore {$rowCount} rows from {$entry->id}");
            $ctx->output->line('re-run with --yes to apply');

            return 0;
        }

        $ctx->output->record([
            'dry_run' => true,
            'journal_id' => $entry->id,
            'would_restore' => $rowCount,
        ]);

        return 0;
    }

    private function list(Context $ctx, JournalWriter $journal): int
    {
        $rows = [];

        foreach ($journal->recent() as $entry) {
            $rows[] = [
                'id' => $entry->id,
                'operation' => $entry->operation,
                'resource' => $entry->resource,
                'rows' => count($entry->rows),
                'committed' => $entry->committed,
                'reverted_at' => $entry->revertedAt,
            ];
        }

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->rows($rows);

            return 0;
        }

        $ctx->output->record(['entries' => $rows]);

        return 0;
    }
}
