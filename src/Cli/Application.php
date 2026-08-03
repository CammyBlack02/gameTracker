<?php

namespace GameTracker\Cli;

use GameTracker\Cli\Commands\DbInfoCommand;
use GameTracker\Cli\Commands\WhoamiCommand;
use PDO;
use Throwable;

/**
 * Argument parsing and command dispatch for bin/gt.
 */
final class Application
{
    public const VERSION = '0.1.0';

    public const EXIT_OK = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_USAGE = 2;
    public const EXIT_BOOTSTRAP = 3;

    /** @var array<string, class-string<Command>> */
    private const COMMANDS = [
        WhoamiCommand::NAME => WhoamiCommand::class,
        DbInfoCommand::NAME => DbInfoCommand::class,
    ];

    /**
     * Handle the invocations that must work without a database — `gt help`,
     * `gt --version`, and a bare `gt`. bin/gt calls this before requiring
     * config.php, so a broken or unconfigured connection still leaves the CLI
     * self-documenting instead of failing to explain itself.
     *
     * @return int|null Exit code if handled, null to continue with a database.
     */
    public static function earlyExit(array $argv): ?int
    {
        array_shift($argv);

        $wantsHelp = false;
        $positional = [];

        foreach ($argv as $arg) {
            if ($arg === '--version' || $arg === '-V') {
                fwrite(STDOUT, 'gt ' . self::VERSION . "\n");
                return self::EXIT_OK;
            }
            if ($arg === '-h' || $arg === '--help' || $arg === 'help') {
                $wantsHelp = true;
                continue;
            }
            if (!str_starts_with($arg, '-')) {
                $positional[] = $arg;
            }
        }

        // `gt help` on its own, or any invocation asking for help without also
        // naming a command. `gt games list --help` falls through so the command
        // can render its own usage later.
        if ($wantsHelp && $positional === []) {
            self::usage();
            return self::EXIT_OK;
        }

        if ($argv === []) {
            self::usage();
            return self::EXIT_USAGE;
        }

        return null;
    }

    public static function main(array $argv, ?PDO $pdo): int
    {
        array_shift($argv); // script name

        $format = Output::FORMAT_JSON;
        $userRef = getenv('GT_USER') ?: null;
        $http = false;
        $args = [];

        // Global flags may appear anywhere; anything else is positional.
        foreach ($argv as $arg) {
            switch (true) {
                case $arg === '--json':
                    $format = Output::FORMAT_JSON;
                    break;
                case $arg === '--table':
                    $format = Output::FORMAT_TABLE;
                    break;
                case $arg === '--http':
                    $http = true;
                    break;
                case $arg === '-h' || $arg === '--help':
                    $args[] = 'help';
                    break;
                case $arg === '--version' || $arg === '-V':
                    fwrite(STDOUT, 'gt ' . self::VERSION . "\n");
                    return self::EXIT_OK;
                case str_starts_with($arg, '--user='):
                    $userRef = substr($arg, strlen('--user='));
                    break;
                case str_starts_with($arg, '-'):
                    fwrite(STDERR, "error: unknown option '{$arg}'\n");
                    return self::EXIT_USAGE;
                default:
                    $args[] = $arg;
            }
        }

        $output = new Output($format);
        $name = array_shift($args);

        if ($name === null || $name === 'help') {
            self::usage();
            return $name === null ? self::EXIT_USAGE : self::EXIT_OK;
        }

        if (!isset(self::COMMANDS[$name])) {
            $output->error("unknown command '{$name}'. Run `gt help` for the list.");
            return self::EXIT_USAGE;
        }

        // Phase D will implement --http. Fail loudly rather than silently
        // running in-process and reporting results the HTTP layer never saw.
        if ($http) {
            $output->error('--http is not implemented yet (planned for Phase D).');
            return self::EXIT_USAGE;
        }

        if (!$pdo instanceof PDO) {
            $output->error('no database connection available — check includes/config.php');
            return self::EXIT_BOOTSTRAP;
        }

        $ctx = new Context($pdo, $output, $userRef, $http);

        /** @var class-string<Command> $class */
        $class = self::COMMANDS[$name];

        try {
            return (new $class())->run($args, $ctx);
        } catch (Throwable $e) {
            // Full detail is fine here: the CLI runs for the operator, unlike an
            // HTTP response body where CLAUDE.md forbids leaking exception text.
            $output->error($e->getMessage());
            return self::EXIT_ERROR;
        }
    }

    private static function usage(): void
    {
        $lines = [
            'gt ' . self::VERSION . ' — gameTracker command line',
            '',
            'Usage: gt [global options] <command> [args]',
            '',
            'Commands:',
        ];

        foreach (self::COMMANDS as $name => $class) {
            $lines[] = sprintf('  %-10s %s', $name, $class::description());
        }

        $lines = array_merge($lines, [
            '  help       Show this message',
            '',
            'Global options:',
            '  --json           Machine-readable output (default)',
            '  --table          Human-readable table output',
            '  --user=<ref>     Act as this user (username or id). Env: GT_USER',
            '  --http           Drive api/v2 over HTTP instead of in-process (Phase D)',
            '  --version, -V    Print version',
            '',
            'Exit codes: 0 ok, 1 error, 2 usage, 3 bootstrap/database',
        ]);

        fwrite(STDOUT, implode("\n", $lines) . "\n");
    }
}
