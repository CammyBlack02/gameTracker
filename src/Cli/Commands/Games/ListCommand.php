<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UserResolver;
use GameTracker\Query\FilterCompiler;
use GameTracker\Query\GamesFilters;
use GameTracker\Services\GamesService;

final class ListCommand implements Command
{
    public const NAME = 'games list';

    /** Columns worth showing in a terminal; JSON always carries the full row. */
    private const TABLE_COLUMNS = ['id', 'title', 'platform', 'played', 'star_rating'];

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'List games, with filters';
    }

    public static function allowedOptions(): array
    {
        return GamesFilters::definition()->flagNames();
    }

    public function run(array $args, Context $ctx): int
    {
        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $filters = FilterCompiler::compile(GamesFilters::definition(), $ctx);

        $result = GamesService::list($ctx->pdo, (int)$user['id'], $filters);

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->rows($result['games'], self::TABLE_COLUMNS);
            $ctx->output->line(sprintf(
                '(page %d of %d, %d total)',
                $result['pagination']['page'],
                $result['pagination']['total_pages'],
                $result['pagination']['total']
            ));

            return 0;
        }

        $ctx->output->record($result);

        return 0;
    }
}
