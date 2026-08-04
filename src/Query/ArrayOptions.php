<?php

namespace GameTracker\Query;

use GameTracker\Cli\UsageException;

/**
 * An OptionSource over a plain array — in practice $_GET.
 *
 * Deliberately mirrors Context's semantics rather than inventing its own. Any
 * divergence here shows up as a parity failure between `gt games list` and
 * `GET /api/v2/games/list.php`, which is exactly what the parity suite exists
 * to catch.
 */
final class ArrayOptions implements OptionSource
{
    public function __construct(
        private readonly array $values = [],
    ) {
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->values[$name] ?? null;

        if (!is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }

    /**
     * Presence, not value. A query string writes a valueless flag as
     * `?unplayed` (empty string) or `?unplayed=1`; both mean the same thing,
     * just as `--unplayed` does on the CLI.
     */
    public function flag(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    public function intOption(string $name, int $default): int
    {
        $raw = $this->values[$name] ?? null;

        if (!is_string($raw) || $raw === '') {
            return $default;
        }

        // Match Context: reject junk rather than let a cast turn ?page=abc into
        // 0 and then silently clamp. Two transports must fail the same way on
        // bad input, not just succeed the same way on good input.
        if (!preg_match('/^-?\d+$/', $raw)) {
            throw new UsageException("{$name} must be an integer, got '{$raw}'");
        }

        return (int)$raw;
    }
}
