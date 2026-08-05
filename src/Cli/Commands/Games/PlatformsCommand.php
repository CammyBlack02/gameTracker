<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UserResolver;
use GameTracker\Query\FilterCompiler;
use GameTracker\Query\FilterSet;
use GameTracker\Query\GamesFilters;
use GameTracker\Services\GamesService;

/**
 * Platform names are matched exactly by --platform, and the stored values are
 * not always what you would guess ("PlayStation 2", not "PS2"), so this is how
 * you find the string to filter on. The count makes it a collection summary as
 * well, which is what stops the per-platform split needing a gt sql aggregate.
 */
final class PlatformsCommand implements Command
{
    public const NAME = 'games platforms';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'List platforms with a game count, with filters';
    }

    public static function allowedOptions(): array
    {
        // selectorNames() rather than flagNames(): it is already defined as the
        // flags that narrow which rows match, excluding presentation flags. That
        // makes --limit/--page/--per-page unknown options here, which is what we
        // want — paging an aggregate is meaningless, and silently ignoring the
        // flag would be worse than refusing it.
        return array_merge(GamesFilters::definition()->selectorNames(), ['sort']);
    }

    public function run(array $args, Context $ctx): int
    {
        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);

        [$whereSql, $params] = FilterCompiler::compileWhere(GamesFilters::definition(), $ctx);

        $counts = GamesService::platformCounts(
            $ctx->pdo,
            (int)$user['id'],
            FilterSet::forSummary($whereSql, $params, '`platform` ASC')
        );

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->rows($counts);

            return 0;
        }

        $ctx->output->record(['platforms' => $counts]);

        return 0;
    }
}
