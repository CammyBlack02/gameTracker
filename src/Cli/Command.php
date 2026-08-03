<?php

namespace GameTracker\Cli;

interface Command
{
    /** Command name as typed, e.g. "db:info". */
    public static function name(): string;

    /** One-line description for `gt help`. */
    public static function description(): string;

    /**
     * Long-option names this command accepts, without leading dashes and
     * excluding the global ones (--json/--table/--user/--http).
     *
     * Application validates against this list so an unknown flag is a usage
     * error rather than silently ignored — a typo'd --per-page must never
     * quietly fall back to the default page size.
     *
     * @return list<string>
     */
    public static function allowedOptions(): array;

    /**
     * @param array $args Positional arguments after the command name.
     * @return int Process exit code.
     */
    public function run(array $args, Context $ctx): int;
}
