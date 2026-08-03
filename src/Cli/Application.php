<?php

namespace GameTracker\Cli;

use GameTracker\Cli\Commands\DbInfoCommand;
use GameTracker\Cli\Commands\Games\GetCommand as GamesGetCommand;
use GameTracker\Cli\Commands\Games\ListCommand as GamesListCommand;
use GameTracker\Cli\Commands\Games\PlatformsCommand as GamesPlatformsCommand;
use GameTracker\Cli\Commands\Games\SetCommand as GamesSetCommand;
use GameTracker\Cli\Commands\Items\GetCommand as ItemsGetCommand;
use GameTracker\Cli\Commands\Items\ListCommand as ItemsListCommand;
use GameTracker\Cli\Commands\WhoamiCommand;
use GameTracker\Domain\DomainException;
use PDO;
use Throwable;

/**
 * Argument parsing and command dispatch for bin/gt.
 */
final class Application
{
    public const VERSION = '0.2.0';

    public const EXIT_OK = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_USAGE = 2;
    public const EXIT_BOOTSTRAP = 3;

    /**
     * Keys are full command strings. Dispatch prefers the two-token match, so
     * "games list" coexists with "whoami" without needing a nested registry.
     *
     * @var array<string, class-string<Command>>
     */
    private const COMMANDS = [
        'whoami' => WhoamiCommand::class,
        'db info' => DbInfoCommand::class,
        'games list' => GamesListCommand::class,
        'games get' => GamesGetCommand::class,
        'games platforms' => GamesPlatformsCommand::class,
        'games set' => GamesSetCommand::class,
        'items list' => ItemsListCommand::class,
        'items get' => ItemsGetCommand::class,
    ];

    /** Global flags consumed here, never forwarded to commands. */
    private const GLOBAL_OPTIONS = ['json', 'table', 'http', 'user', 'help', 'version'];

    /**
     * Handle the invocations that must work without a database — `gt help`,
     * `gt --version`, and a bare `gt`. bin/gt calls this before requiring
     * config.php, so a broken or unconfigured connection still leaves the CLI
     * able to explain itself.
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

        $format = null; // null => Output auto-detects from the TTY
        $userRef = getenv('GT_USER') ?: null;
        $http = false;
        $args = [];
        $options = [];

        foreach ($argv as $arg) {
            if (!str_starts_with($arg, '-')) {
                $args[] = $arg;
                continue;
            }

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
                case str_starts_with($arg, '--'):
                    // Command-specific option. Validated against the resolved
                    // command's allowedOptions() below, so a typo is an error
                    // rather than a silently ignored flag.
                    $body = substr($arg, 2);
                    if (str_contains($body, '=')) {
                        [$name, $value] = explode('=', $body, 2);
                        $options[$name] = $value;
                    } else {
                        $options[$body] = true;
                    }
                    break;
                default:
                    fwrite(STDERR, "error: unknown option '{$arg}'\n");
                    return self::EXIT_USAGE;
            }
        }

        $output = new Output($format);

        if ($args === [] || $args[0] === 'help') {
            self::usage();
            return $args === [] ? self::EXIT_USAGE : self::EXIT_OK;
        }

        [$name, $args] = self::resolve($args);

        if ($name === null) {
            self::reportUnknown($output, $args);
            return self::EXIT_USAGE;
        }

        /** @var class-string<Command> $class */
        $class = self::COMMANDS[$name];

        $unknown = array_diff(array_keys($options), $class::allowedOptions(), self::GLOBAL_OPTIONS);
        if ($unknown !== []) {
            $output->error(
                "unknown option '--" . reset($unknown) . "' for `gt {$name}`. "
                . 'Run `gt help` for the list.'
            );
            return self::EXIT_USAGE;
        }

        if ($http) {
            $output->error('--http is not implemented yet (planned for a later sub-project).');
            return self::EXIT_USAGE;
        }

        if (!$pdo instanceof PDO) {
            $output->error('no database connection available — check includes/config.php');
            return self::EXIT_BOOTSTRAP;
        }

        $ctx = new Context($pdo, $output, $userRef, $http, $options);

        try {
            return (new $class())->run($args, $ctx);
        } catch (UsageException $e) {
            $output->error($e->getMessage());
            return self::EXIT_USAGE;
        } catch (DomainException $e) {
            $output->error($e->getMessage());
            return self::EXIT_ERROR;
        } catch (Throwable $e) {
            // Full detail is fine here: the CLI runs for the operator, unlike an
            // HTTP response body where CLAUDE.md forbids leaking exception text.
            $output->error($e->getMessage());
            return self::EXIT_ERROR;
        }
    }

    /**
     * Two-token match first, then single-token.
     *
     * @return array{0: string|null, 1: array} Command name (null if unknown) and remaining args.
     */
    private static function resolve(array $args): array
    {
        if (count($args) >= 2) {
            $two = $args[0] . ' ' . $args[1];
            if (isset(self::COMMANDS[$two])) {
                return [$two, array_slice($args, 2)];
            }
        }

        if (isset(self::COMMANDS[$args[0]])) {
            return [$args[0], array_slice($args, 1)];
        }

        return [null, $args];
    }

    /**
     * A known resource with an unknown verb is a different mistake from an
     * unknown resource, and deserves a different message: list the verbs that
     * do exist rather than making the caller run `gt help` to find them.
     */
    private static function reportUnknown(Output $output, array $args): void
    {
        $resource = $args[0];
        $verbs = [];

        foreach (array_keys(self::COMMANDS) as $command) {
            if (str_starts_with($command, $resource . ' ')) {
                $verbs[] = substr($command, strlen($resource) + 1);
            }
        }

        if ($verbs !== []) {
            $output->error(
                "unknown subcommand for '{$resource}'. Available: " . implode(', ', $verbs)
            );
            return;
        }

        $output->error("unknown command '{$resource}'. Run `gt help` for the list.");
    }

    private static function usage(): void
    {
        $lines = [
            'gt ' . self::VERSION . ' — gameTracker command line',
            '',
            'Usage: gt [global options] <command> [args] [filters]',
            '',
            'Commands:',
        ];

        foreach (self::COMMANDS as $name => $class) {
            $lines[] = sprintf('  %-16s %s', $name, $class::description());
        }

        $lines = array_merge($lines, [
            '  help             Show this message',
            '',
            'Global options:',
            '  --json           Force machine-readable output',
            '  --table          Force human-readable table output',
            '                   (default: table on a terminal, JSON when piped)',
            '  --user=<ref>     Act as this user (username or id). Env: GT_USER',
            '  --http           Drive api/v2 over HTTP instead of in-process (not yet)',
            '  --version, -V    Print version',
            '',
            'Exit codes: 0 ok, 1 error, 2 usage, 3 bootstrap/database',
        ]);

        fwrite(STDOUT, implode("\n", $lines) . "\n");
    }
}
