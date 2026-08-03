<?php

namespace GameTracker\Domain;

use RuntimeException;

/**
 * Base class for failures a service raises on purpose.
 *
 * Every subclass carries a stable machine-readable slug. That slug is the
 * single source of truth for how a failure is surfaced:
 *
 *   - v2 endpoints map it onto the error envelope's "error" field
 *   - v1 endpoints map it onto an HTTP status + {"success": false}
 *   - the CLI maps it onto an exit code
 *
 * Services never format messages for a particular transport, and never write
 * to output. They throw; the caller decides what the failure looks like.
 */
abstract class DomainException extends RuntimeException
{
    /** Stable error slug, e.g. "not_found". */
    abstract public function slug(): string;

    /** HTTP status the slug corresponds to, for the endpoint wrappers. */
    abstract public function httpStatus(): int;
}
