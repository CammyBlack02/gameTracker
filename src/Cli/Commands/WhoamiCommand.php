<?php

namespace GameTracker\Cli\Commands;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\UserResolver;

/**
 * Reports which user the CLI is acting as, and the size of their collection.
 *
 * Deliberately the first command: every later command scopes its queries to
 * this identity, so being able to confirm it cheaply matters.
 */
final class WhoamiCommand implements Command
{
    public const NAME = 'whoami';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Show the acting user and their collection totals';
    }

    public static function allowedOptions(): array
    {
        return [];
    }

    public function run(array $args, Context $ctx): int
    {
        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $userId = (int)$user['id'];

        $ctx->output->record([
            'id' => $userId,
            'username' => $user['username'],
            'role' => $user['role'],
            'games' => $this->countFor($ctx, 'games', $userId),
            'items' => $this->countFor($ctx, 'items', $userId),
            'completions' => $this->countFor($ctx, 'game_completions', $userId),
            'source' => $ctx->userRef !== null ? 'explicit' : 'sole user',
        ]);

        return 0;
    }

    private function countFor(Context $ctx, string $table, int $userId): int
    {
        // Table name is a literal from this class, never user input; the
        // user id is still bound.
        $stmt = $ctx->pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE user_id = ?");
        $stmt->execute([$userId]);

        return (int)$stmt->fetchColumn();
    }
}
