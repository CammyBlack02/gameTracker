<?php

namespace GameTracker\Cli\Commands\Items;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Journal\JournalWriter;
use GameTracker\Query\FilterCompiler;
use GameTracker\Query\FilterSet;
use GameTracker\Query\ItemsFilters;
use GameTracker\Services\ItemsService;
use GameTracker\Services\Write\ItemsWriter;

/**
 * Deletes items. Always requires --yes, for the same reason games delete does.
 */
final class DeleteCommand implements Command
{
    public const NAME = 'items delete';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Remove one item or many (always needs --yes)';
    }

    public static function allowedOptions(): array
    {
        return array_merge(
            ItemsFilters::definition()->flagNames(),
            ['yes', 'all'],
        );
    }

    public function run(array $args, Context $ctx): int
    {
        $filterDef = ItemsFilters::definition();

        $id = $args[0] ?? null;
        $hasSelector = array_intersect(array_keys($ctx->options), $filterDef->selectorNames()) !== [];

        if ($id !== null) {
            if (!preg_match('/^\d+$/', $id)) {
                throw new UsageException("item id must be a positive integer, got '{$id}'");
            }
            if ($hasSelector) {
                throw new UsageException(
                    'pass either an id or filters, not both — filters do not narrow an id'
                );
            }
        } elseif (!$hasSelector && !$ctx->flag('all')) {
            throw new UsageException(
                'no selector given — add a filter (see `gt items list --help`) or --all to delete every item'
            );
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $userId = (int)$user['id'];

        $filters = $id !== null
            ? FilterSet::forId((int)$id)
            : FilterCompiler::compile($filterDef, $ctx);

        if ($id !== null) {
            ItemsWriter::assertOwned($ctx->pdo, $userId, (int)$id);
        }

        $matched = ItemsService::countMatching($ctx->pdo, $userId, $filters);

        if (!$ctx->flag('yes')) {
            if ($ctx->output->format() === Output::FORMAT_TABLE) {
                $ctx->output->line("would delete {$matched} rows");
                $ctx->output->line('re-run with --yes to apply');

                return 0;
            }

            $ctx->output->record(['dry_run' => true, 'matched' => $matched]);

            return 0;
        }

        $result = ItemsWriter::applyDelete(
            $ctx->pdo,
            $userId,
            $filters,
            new JournalWriter(),
            array_slice($_SERVER['argv'] ?? [], 1)
        );

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line(sprintf('deleted %d', $result['deleted']));
            if ($result['journal_id'] !== null) {
                $ctx->output->line('undo with: gt undo ' . $result['journal_id'] . ' --yes');
            }

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'deleted' => $result['deleted'],
            'journal_id' => $result['journal_id'],
        ]);

        return 0;
    }
}
