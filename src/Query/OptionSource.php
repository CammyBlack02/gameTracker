<?php

namespace GameTracker\Query;

/**
 * Where a filter's values come from.
 *
 * FilterCompiler used to take the CLI's Context directly, which made the filter
 * vocabulary a CLI feature. It is a domain feature: `--platform=PS2` and
 * `?platform=PS2` should compile to the same query, or the HTTP endpoints
 * cannot share the CLI's filters and the two drift apart.
 *
 * Three methods, because that is all FilterCompiler ever used.
 */
interface OptionSource
{
    /** A string option, or $default when absent or valueless. */
    public function option(string $name, ?string $default = null): ?string;

    /** Presence, not truthiness — `?unplayed` and `--unplayed` carry no value. */
    public function flag(string $name): bool;

    /** An integer option, rejecting non-numeric input rather than casting it. */
    public function intOption(string $name, int $default): int;
}
