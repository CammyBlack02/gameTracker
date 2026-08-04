<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\Http\HttpClient;
use GameTracker\Cli\UserResolver;
use GameTracker\Query\FilterCompiler;
use GameTracker\Images\BrokenCover;
use GameTracker\Images\ImageIndex;
use GameTracker\Query\FilterSet;
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

    /** A --broken-cover scan must see every row, so paging is effectively off. */
    private const SCAN_LIMIT = 100000;

    public static function allowedOptions(): array
    {
        return array_merge(GamesFilters::definition()->flagNames(), ['broken-cover']);
    }

    public function run(array $args, Context $ctx): int
    {
        // --http sends the same command to api/v2/games/list.php. The filter
        // flags become query parameters unchanged, which is the point: one
        // vocabulary, two transports.
        if ($ctx->http) {
            return $this->overHttp($ctx, 'api/v2/games/list.php');
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $filters = FilterCompiler::compile(GamesFilters::definition(), $ctx);

        // --broken-cover cannot compile to SQL — the filesystem is not
        // something a WHERE clause can consult — so it runs over fetched rows.
        // That means it must see ALL of them: filtering after LIMIT would
        // report "3 broken on this page" and present it as the total.
        $brokenCover = $ctx->flag('broken-cover');
        if ($brokenCover) {
            $filters = new FilterSet(
                $filters->whereSql,
                $filters->params,
                $filters->orderSql,
                1,
                self::SCAN_LIMIT,
                0
            );
        }

        $result = GamesService::list($ctx->pdo, (int)$user['id'], $filters);

        if ($brokenCover) {
            $result['games'] = BrokenCover::filter(
                $result['games'],
                'games',
                ImageIndex::uploadsDir()
            );
            $total = count($result['games']);
            $result['pagination'] = [
                'page' => 1,
                'per_page' => $total,
                'total' => $total,
                'total_pages' => 1,
                'has_more' => false,
            ];
        }

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

    /**
     * Run this command against a v2 endpoint instead of the service layer.
     *
     * Forwards every command-specific option as a query parameter. A valueless
     * flag stays valueless so ArrayOptions::flag() reads it the same way
     * Context::flag() does.
     */
    private function overHttp(Context $ctx, string $path): int
    {
        $client = HttpClient::fromEnvironment();

        $query = [];
        foreach ($ctx->options as $key => $value) {
            $query[$key] = $value === true ? true : (string)$value;
        }

        $ctx->output->record($client->get($path, $query));

        return 0;
    }
}
