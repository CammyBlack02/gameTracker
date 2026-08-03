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
    ) {
    }
}
