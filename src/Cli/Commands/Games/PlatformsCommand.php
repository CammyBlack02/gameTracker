<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
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

    /**
     * The sort vocabulary, as ready-to-splice ORDER BY bodies.
     *
     * A fixed map rather than a column allowlist because "games" is a COUNT(*)
     * alias, and because the count needs a tiebreaker while the platform name —
     * the GROUP BY key, so unique per row — cannot tie. Nothing here comes from
     * input; the key is looked up, never interpolated.
     */
    private const SORTS = [
        'platform' => '`platform` ASC',
        '-platform' => '`platform` DESC',
        'games' => '`games` ASC, `platform` ASC',
        '-games' => '`games` DESC, `platform` ASC',
    ];

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
            FilterSet::forSummary($whereSql, $params, self::orderSql($ctx))
        );

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->rows($counts);

            return 0;
        }

        $ctx->output->record(['platforms' => $counts]);

        return 0;
    }

    private static function orderSql(Context $ctx): string
    {
        // Alphabetical by default: this command's first job is finding the exact
        // string to pass to --platform, and --sort=-games is for reading the
        // collection rather than filtering it.
        $sort = $ctx->option('sort') ?? 'platform';

        if (!isset(self::SORTS[$sort])) {
            throw new UsageException(
                "--sort={$sort} is not sortable on a platform summary. "
                . 'Available: ' . implode(', ', array_keys(self::SORTS))
            );
        }

        return self::SORTS[$sort];
    }
}
