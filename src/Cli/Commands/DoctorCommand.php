<?php

namespace GameTracker\Cli\Commands;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Diagnostics\BackupCheck;
use GameTracker\Diagnostics\Check;
use GameTracker\Diagnostics\ConfigCheck;
use GameTracker\Diagnostics\ImageCheck;
use GameTracker\Diagnostics\SchemaCheck;
use Throwable;

/**
 * Reports whether the system is in the state you think it is.
 *
 * Exits non-zero when a check fails, which is what makes it worth wiring into
 * cron rather than only reading when already suspicious.
 *
 * Read-only by construction. Every fix has a home elsewhere — migrate.php,
 * gt images prune, the backup script — and a doctor that also mutates is two
 * tools wearing one coat.
 */
final class DoctorCommand implements Command
{
    public const NAME = 'doctor';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Check schema, config, backups and images; exit 1 if anything is wrong';
    }

    public static function allowedOptions(): array
    {
        return [];
    }

    public function run(array $args, Context $ctx): int
    {
        $dbName = (string)$ctx->pdo->query('SELECT DATABASE()')->fetchColumn();

        $checks = [];

        foreach ([
            'schema' => fn(): array => SchemaCheck::run($ctx->pdo, $dbName),
            'config' => fn(): array => ConfigCheck::run(),
            'backups' => fn(): array => BackupCheck::run(),
            'images' => fn(): array => ImageCheck::run($ctx->pdo),
        ] as $group => $runner) {
            try {
                $checks = array_merge($checks, $runner());
            } catch (Throwable $e) {
                // A check that throws is a finding, not a reason to stop. A
                // doctor that dies on the first problem is useless exactly
                // when it is needed most.
                $checks[] = Check::fail(
                    $group . '-check',
                    'check could not run: ' . $e->getMessage()
                );
            }
        }

        $failed = count(array_filter($checks, static fn(Check $c): bool => $c->failed()));
        $exit = Check::worstExitCode($checks);

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            foreach ($checks as $check) {
                $marker = match ($check->status) {
                    Check::PASS => '  ok  ',
                    Check::FAIL => ' FAIL ',
                    default => ' note ',
                };
                $ctx->output->line(sprintf('%s %-28s %s', $marker, $check->name, $check->summary));
                if ($check->failed() && $check->remedy !== null) {
                    $ctx->output->line(sprintf('       %-28s → %s', '', $check->remedy));
                }
            }

            $ctx->output->line('');
            $ctx->output->line($failed === 0
                ? sprintf('%d checks, all clear', count($checks))
                : sprintf('%d checks, %d failed', count($checks), $failed));

            return $exit;
        }

        $ctx->output->record([
            'checks' => array_map(static fn(Check $c): array => $c->toArray(), $checks),
            'total' => count($checks),
            'failed' => $failed,
        ]);

        return $exit;
    }
}
