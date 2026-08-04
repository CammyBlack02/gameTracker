<?php

namespace GameTracker\Cli\Commands;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use PDO;

/**
 * Lists the accounts on this install with what each one owns.
 *
 * This is an operator command, not a user-facing one: it reports across every
 * account rather than scoping to the caller, the same way `db info` and `doctor`
 * do. Anyone who can run `gt` already holds the database credentials, so there is
 * nothing here they could not read directly — but it is deliberately read-only,
 * and it never prints a password hash. A hash in terminal scrollback or a piped
 * JSON log is an offline cracking target for no operational benefit.
 *
 * The row counts exist because "who owns what" is the question that actually
 * comes up: which account a stray game belongs to, whether a user is dormant, and
 * whether an account can be safely ignored. Counting per user in one grouped
 * query rather than N+1 lookups keeps it usable if the install ever grows.
 */
final class UsersListCommand implements Command
{
    public const NAME = 'users list';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'List accounts with their role and what each one owns';
    }

    public static function allowedOptions(): array
    {
        return [];
    }

    public function run(array $args, Context $ctx): int
    {
        if ($args !== []) {
            $ctx->output->error('users list takes no arguments');
            return 2;
        }

        $users = $ctx->pdo->query(
            'SELECT `id`, `username`, `role`, `created_at`
             FROM users
             ORDER BY `id`'
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($users === []) {
            // Not an error: a fresh install before the baseline seed runs is
            // legitimately empty, and exiting 1 would make `doctor`-style
            // chaining awkward.
            $ctx->output->warn('no accounts exist in this database');
            $ctx->output->rows([]);
            return 0;
        }

        $games = $this->countsByUser($ctx->pdo, 'games');
        $items = $this->countsByUser($ctx->pdo, 'items');
        $completions = $this->countsByUser($ctx->pdo, 'game_completions');
        $tokens = $this->countsByUser($ctx->pdo, 'api_tokens');

        $rows = [];
        foreach ($users as $user) {
            $id = (int)$user['id'];
            $rows[] = [
                'id' => $id,
                'username' => $user['username'],
                'role' => $user['role'],
                'games' => $games[$id] ?? 0,
                'items' => $items[$id] ?? 0,
                'completions' => $completions[$id] ?? 0,
                'api_tokens' => $tokens[$id] ?? 0,
                'created_at' => $user['created_at'],
            ];
        }

        $ctx->output->rows($rows, [
            'id', 'username', 'role', 'games', 'items', 'completions', 'api_tokens', 'created_at',
        ]);

        return 0;
    }

    /**
     * Rows per user for one table, as [user_id => count].
     *
     * The table name is a hardcoded literal at every call site, never input —
     * the same rule the filter allowlist follows, so interpolating it here cannot
     * become an injection point.
     *
     * @return array<int, int>
     */
    private function countsByUser(PDO $pdo, string $table): array
    {
        $stmt = $pdo->query("SELECT `user_id`, COUNT(*) AS c FROM `{$table}` GROUP BY `user_id`");

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ($row['user_id'] === null) {
                continue;
            }
            $out[(int)$row['user_id']] = (int)$row['c'];
        }

        return $out;
    }
}
