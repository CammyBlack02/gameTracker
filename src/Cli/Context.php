<?php

namespace GameTracker\Cli;

use PDO;

/**
 * Everything a command is allowed to depend on, passed explicitly.
 *
 * No globals, no ambient $_SESSION. This mirrors the service-layer rule from
 * docs/superpowers/specs/2026-08-03-gt-cli-design.md: identity and the database
 * handle travel as parameters, so user scoping stays visible instead of hidden.
 */
final class Context
{
    public function __construct(
        public readonly PDO $pdo,
        public readonly Output $output,
        /** Raw --user value (username or numeric id), unresolved. */
        public readonly ?string $userRef = null,
        /** Reserved for Phase D: drive api/v2 over HTTP instead of in-process. */
        public readonly bool $http = false,
        /** Command-specific long options, name => string value or true. */
        public readonly array $options = [],
    ) {
    }

    public function option(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        // A valueless flag arrives as true; asking for its string value is a
        // caller bug, so fall back rather than returning "1".
        return is_string($value) ? $value : $default;
    }

    public function flag(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    /**
     * Integer option with validation. Rejects non-numeric input instead of
     * letting a cast turn --page=abc into 0 and then silently clamp to 1.
     */
    public function intOption(string $name, int $default): int
    {
        $raw = $this->option($name);

        if ($raw === null || $raw === '') {
            return $default;
        }

        if (!preg_match('/^-?\d+$/', $raw)) {
            throw new UsageException("--{$name} must be an integer, got '{$raw}'");
        }

        return (int)$raw;
    }
}
