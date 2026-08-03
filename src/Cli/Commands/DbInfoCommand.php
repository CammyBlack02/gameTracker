<?php

namespace GameTracker\Cli\Commands;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;

/**
 * Reports which database the CLI is pointed at and what state its schema is in.
 *
 * Two of these fields exist because of a real drift found on 2026-08-03:
 * production had no schema_migrations ledger at all, and its untracked
 * includes/config.php still called initializeDatabase() on every request long
 * after the committed template had stopped. Both conditions are invisible from
 * the code alone, so the CLI reports them rather than assuming.
 */
final class DbInfoCommand implements Command
{
    public const NAME = 'db info';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Show the target database, schema state and migration ledger';
    }

    public static function allowedOptions(): array
    {
        return [];
    }

    public function run(array $args, Context $ctx): int
    {
        $pdo = $ctx->pdo;
        $dbName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

        if ($dbName === '') {
            $ctx->output->error('connected but no database selected — refusing to guess');
            return 1;
        }

        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $ledger = $this->ledgerState($ctx, $dbName);

        $info = [
            'database' => $dbName,
            'host' => defined('DB_HOST') ? DB_HOST : '(unknown)',
            'server_version' => $pdo->getAttribute(\PDO::ATTR_SERVER_VERSION),
            'tables' => count($tables),
            'migrations_on_disk' => count($this->migrationsOnDisk()),
            'migrations_applied' => $ledger['applied'],
            'ledger_present' => $ledger['present'],
            'legacy_per_request_ddl' => function_exists('initializeDatabase'),
        ];

        $ctx->output->record($info);

        // Surface the two drift conditions as warnings on STDERR, so JSON on
        // STDOUT stays clean for piping while a human still sees the problem.
        if (!$ledger['present']) {
            $ctx->output->warn(
                'schema_migrations does not exist — `php database/migrate.php` has never run '
                . 'against this database, so the ledger is not the source of truth here'
            );
        }

        if (function_exists('initializeDatabase')) {
            $ctx->output->warn(
                'includes/config.php still defines initializeDatabase() — this build fires DDL '
                . 'on every web request. The committed config.php.example no longer does.'
            );
        }

        return 0;
    }

    /**
     * @return array{present: bool, applied: int|null}
     */
    private function ledgerState(Context $ctx, string $dbName): array
    {
        $stmt = $ctx->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = ? AND table_name = ?'
        );
        $stmt->execute([$dbName, 'schema_migrations']);

        if ((int)$stmt->fetchColumn() === 0) {
            return ['present' => false, 'applied' => null];
        }

        $applied = (int)$ctx->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();

        return ['present' => true, 'applied' => $applied];
    }

    /**
     * @return string[]
     */
    private function migrationsOnDisk(): array
    {
        $dir = dirname(__DIR__, 3) . '/database/migrations';

        if (!is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(
            scandir($dir) ?: [],
            static fn(string $f): bool => str_ends_with($f, '.php')
        ));
    }
}
