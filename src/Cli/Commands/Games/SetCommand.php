<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Journal\JournalWriter;
use GameTracker\Query\FilterCompiler;
use GameTracker\Query\FilterSet;
use GameTracker\Query\GamesFilters;
use GameTracker\Services\GamesService;
use GameTracker\Services\Write\GamesWriter;
use GameTracker\Write\AssignmentSet;
use GameTracker\Write\GamesWrites;

final class SetCommand implements Command
{
    public const NAME = 'games set';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Change fields on one game or many';
    }

    public static function allowedOptions(): array
    {
        return array_merge(
            GamesFilters::definition()->flagNames(),
            GamesWrites::definition()->flagNames(),
            ['yes', 'all'],
        );
    }

    public function run(array $args, Context $ctx): int
    {
        $filterDef = GamesFilters::definition();
        $writeDef = GamesWrites::definition();

        $assignments = AssignmentSet::parse($writeDef, $ctx);
        if ($assignments->isEmpty()) {
            throw new UsageException(
                'nothing to set — pass --set-<column>=<value> or --clear-<column>'
            );
        }

        $id = $args[0] ?? null;
        $hasSelector = array_intersect(array_keys($ctx->options), $filterDef->selectorNames()) !== [];

        if ($id !== null) {
            if (!preg_match('/^\d+$/', $id)) {
                throw new UsageException("game id must be a positive integer, got '{$id}'");
            }
            if ($hasSelector) {
                throw new UsageException(
                    'pass either an id or filters, not both — filters do not narrow an id'
                );
            }
        } elseif (!$hasSelector && !$ctx->flag('all')) {
            // Without this, `gt games set --set-played` would mark an entire
            // collection played. Requiring --all makes that intent explicit.
            throw new UsageException(
                'no selector given — add a filter (see `gt games list --help`) or --all to target every game'
            );
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $userId = (int)$user['id'];

        $filters = $id !== null
            ? FilterSet::forId((int)$id)
            : FilterCompiler::compile($filterDef, $ctx);

        $matched = GamesService::countMatching($ctx->pdo, $userId, $filters);
        $single = $id !== null;

        // --yes is required by blast radius, not by command: a single row the
        // caller named explicitly is journalled and one `gt undo` from reverted.
        $needsConfirmation = !$single && $matched > 1;

        if ($needsConfirmation && !$ctx->flag('yes')) {
            return $this->preview($ctx, $matched, $assignments);
        }

        if ($single) {
            GamesWriter::assertOwned($ctx->pdo, $userId, (int)$id);
        }

        return $this->apply($ctx, $userId, $filters, $assignments, $matched);
    }

    private function preview(Context $ctx, int $matched, AssignmentSet $assignments): int
    {
        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line("would update {$matched} rows");
            foreach ($assignments->describe() as $column => $value) {
                $ctx->output->line(sprintf('  %s = %s', $column, $value ?? 'NULL'));
            }
            $ctx->output->line('re-run with --yes to apply');

            return 0;
        }

        $ctx->output->record([
            'dry_run' => true,
            'matched' => $matched,
            'assignments' => $assignments->describe(),
        ]);

        return 0;
    }

    private function apply(
        Context $ctx,
        int $userId,
        FilterSet $filters,
        AssignmentSet $assignments,
        int $matched
    ): int {
        $result = GamesWriter::applySet(
            $ctx->pdo,
            $userId,
            $filters,
            $assignments,
            new JournalWriter(),
            array_slice($_SERVER['argv'] ?? [], 1)
        );

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line(sprintf(
                'matched %d, changed %d',
                $result['matched'],
                $result['changed']
            ));
            if ($result['journal_id'] !== null) {
                $ctx->output->line('undo with: gt undo ' . $result['journal_id']);
            }

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'matched' => $result['matched'],
            'changed' => $result['changed'],
            'journal_id' => $result['journal_id'],
            'assignments' => $assignments->describe(),
        ]);

        return 0;
    }
}
