<?php

namespace GameTracker\Cli\Commands\Games;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Services\GamesService;

final class GetCommand implements Command
{
    public const NAME = 'games get';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Show one game by id, with its extra images';
    }

    public static function allowedOptions(): array
    {
        return ['admin'];
    }

    public function run(array $args, Context $ctx): int
    {
        $id = $args[0] ?? null;

        if ($id === null) {
            throw new UsageException('usage: gt games get <id>');
        }

        if (!preg_match('/^\d+$/', $id)) {
            throw new UsageException("game id must be a positive integer, got '{$id}'");
        }

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);

        // --admin opts into the endpoint's cross-user read, and only takes
        // effect for a user whose role actually is admin — so the escalation
        // cannot happen by passing a flag alone.
        $isAdmin = $ctx->flag('admin') && ($user['role'] ?? '') === 'admin';

        $game = GamesService::get($ctx->pdo, (int)$user['id'], (int)$id, $isAdmin);

        $ctx->output->record($game);

        return 0;
    }
}
