<?php

namespace GameTracker\Cli;

use RuntimeException;

/**
 * The caller typed something wrong: unknown flag, unknown column, malformed
 * value. Application maps this to exit 2, keeping it distinct from a domain
 * error (exit 1) where the request was well-formed but could not be satisfied.
 */
final class UsageException extends RuntimeException
{
}
