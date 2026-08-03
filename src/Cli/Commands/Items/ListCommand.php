<?php

namespace GameTracker\Cli\Commands\Items;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UserResolver;
use GameTracker\Query\FilterCompiler;
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

    public static function allowedOptions(): array
    {
        return ItemsFilters::definition()->flagNames();
    }

    public function run(array $args, Context $ctx): int
    {
        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $filters = FilterCompiler::compile(ItemsFilters::definition(), $ctx);

        $result = ItemsService::list($ctx->pdo, (int)$user['id'], $filters);

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
