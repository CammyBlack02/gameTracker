<?php

namespace GameTracker\Cli;

interface Command
{
    /** Command name as typed, e.g. "db:info". */
    public static function name(): string;

    /** One-line description for `gt help`. */
    public static function description(): string;

    /**
     * @param array $args Positional arguments after the command name.
     * @return int Process exit code.
     */
    public function run(array $args, Context $ctx): int;
}
