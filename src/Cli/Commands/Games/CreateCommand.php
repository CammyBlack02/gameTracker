<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Journal\JournalWriter;
use GameTracker\Services\Write\GamesWriter;
use GameTracker\Write\AssignmentSet;
use GameTracker\Write\GamesWrites;

/**
 * Creates one game.
 *
 * No --yes: a create touches exactly one row the caller described in full, is
 * journalled, and is one `gt undo` away from being removed. Bulk import is
 * sub-project #4's job, not a flag here.
 */
final class CreateCommand implements Command
{
    public const NAME = 'games create';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Add one game';
    }

    public static function allowedOptions(): array
    {
        return GamesWrites::definition()->flagNames();
    }

    public function run(array $args, Context $ctx): int
    {
        if ($args !== []) {
            throw new UsageException(
                'games create takes no positional arguments — describe the row with --set-<column>=<value>'
            );
        }

        $writeDef = GamesWrites::definition();
        $assignments = AssignmentSet::parse($writeDef, $ctx);

        if ($assignments->isEmpty()) {
            throw new UsageException(
                'nothing to create — pass at least --set-title=… and --set-platform=…'
            );
        }

        $missing = $assignments->missingRequired($writeDef);
        if ($missing !== []) {
            throw new UsageException(
                'games create needs ' . implode(' and ', array_map(
                    static fn(string $c): string => '--set-' . $c . '=…',
                    $missing
                ))
            );
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);

        $result = GamesWriter::applyCreate(
            $ctx->pdo,
            (int)$user['id'],
            $assignments,
            new JournalWriter(),
            array_slice($_SERVER['argv'] ?? [], 1)
        );

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line('created game ' . $result['id']);
            $ctx->output->line('undo with: gt undo ' . $result['journal_id']);

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'id' => $result['id'],
            'journal_id' => $result['journal_id'],
            'assignments' => $assignments->describe(),
        ]);

        return 0;
    }
}
