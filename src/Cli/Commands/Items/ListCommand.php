<?php

namespace GameTracker\Cli\Commands\Items;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UserResolver;
use GameTracker\Query\FilterCompiler;
use GameTracker\Images\BrokenCover;
use GameTracker\Images\ImageIndex;
use GameTracker\Query\FilterSet;
use GameTracker\Query\ItemsFilters;
use GameTracker\Services\ItemsService;

final class ListCommand implements Command
{
    public const NAME = 'items list';

    private const TABLE_COLUMNS = ['id', 'title', 'platform', 'category', 'quantity'];

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'List items (accessories), with filters';
    }

    /** A --broken-cover scan must see every row, so paging is effectively off. */
    private const SCAN_LIMIT = 100000;

    public static function allowedOptions(): array
    {
        return array_merge(ItemsFilters::definition()->flagNames(), ['broken-cover']);
    }

    public function run(array $args, Context $ctx): int
    {
        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $filters = FilterCompiler::compile(ItemsFilters::definition(), $ctx);

        // See Games\ListCommand: this is a scan, not a filter.
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

        $result = ItemsService::list($ctx->pdo, (int)$user['id'], $filters);

        if ($brokenCover) {
            $result['items'] = BrokenCover::filter(
                $result['items'],
                'items',
                ImageIndex::uploadsDir()
            );
            $total = count($result['items']);
            $result['pagination'] = [
                'page' => 1,
                'per_page' => $total,
                'total' => $total,
                'total_pages' => 1,
                'has_more' => false,
            ];
        }

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->rows($result['items'], self::TABLE_COLUMNS);
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
