<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UserResolver;
use GameTracker\Services\GamesService;

/**
 * Platform names are matched exactly by --platform, and the stored values are
 * not always what you would guess ("PlayStation 2", not "PS2"), so this is how
 * you find the string to filter on.
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
        return 'List the distinct platforms in your collection';
    }

    public static function allowedOptions(): array
    {
        return [];
    }

    public function run(array $args, Context $ctx): int
    {
        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $platforms = GamesService::platforms($ctx->pdo, (int)$user['id']);

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->rows(array_map(
                static fn(string $p): array => ['platform' => $p],
                $platforms
            ));

            return 0;
        }

        $ctx->output->record(['platforms' => $platforms]);

        return 0;
    }
}
