<?php

namespace GameTracker\Cli\Commands\Items;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Journal\JournalWriter;
use GameTracker\Services\Write\ItemsWriter;
use GameTracker\Write\AssignmentSet;
use GameTracker\Write\ItemsWrites;

/**
 * Creates one item. Same rule as games create: one row, no --yes, journalled.
 */
final class CreateCommand implements Command
{
    public const NAME = 'items create';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Add one item';
    }

    public static function allowedOptions(): array
    {
        return ItemsWrites::definition()->flagNames();
    }

    public function run(array $args, Context $ctx): int
    {
        if ($args !== []) {
            throw new UsageException(
                'items create takes no positional arguments — describe the row with --set-<column>=<value>'
            );
        }

        $writeDef = ItemsWrites::definition();
        $assignments = AssignmentSet::parse($writeDef, $ctx);

        if ($assignments->isEmpty()) {
            throw new UsageException(
                'nothing to create — pass at least --set-title=… and --set-category=…'
            );
        }

        $missing = $assignments->missingRequired($writeDef);
        if ($missing !== []) {
            throw new UsageException(
                'items create needs ' . implode(' and ', array_map(
                    static fn(string $c): string => '--set-' . $c . '=…',
                    $missing
                ))
            );
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);

        $result = ItemsWriter::applyCreate(
            $ctx->pdo,
            (int)$user['id'],
            $assignments,
            new JournalWriter(),
            array_slice($_SERVER['argv'] ?? [], 1)
        );

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line('created item ' . $result['id']);
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
