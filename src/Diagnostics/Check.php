<?php

namespace GameTracker\Diagnostics;

/**
 * One diagnostic result.
 *
 * Three statuses, and only FAIL influences the exit code. That distinction is
 * load-bearing: 99 unreferenced image files are untidy rather than broken, and
 * if they turned `gt doctor` red it would be permanently red — at which point
 * nobody reads it and the command has negative value.
 */
final class Check
{
    public const PASS = 'pass';
    public const FAIL = 'fail';
    public const INFO = 'info';

    private function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $summary,
        /** The command that fixes it, when there is one. */
        public readonly ?string $remedy = null,
    ) {
    }

    public static function pass(string $name, string $summary): self
    {
        return new self($name, self::PASS, $summary);
    }

    public static function fail(string $name, string $summary, ?string $remedy = null): self
    {
        return new self($name, self::FAIL, $summary, $remedy);
    }

    public static function info(string $name, string $summary): self
    {
        return new self($name, self::INFO, $summary);
    }

    public function failed(): bool
    {
        return $this->status === self::FAIL;
    }

    /**
     * 1 if anything failed, 0 otherwise.
     *
     * @param list<self> $checks
     */
    public static function worstExitCode(array $checks): int
    {
        foreach ($checks as $check) {
            if ($check->failed()) {
                return 1;
            }
        }

        return 0;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status,
            'summary' => $this->summary,
            'remedy' => $this->remedy,
        ];
    }
}

$leak = "DELETE FROM games WHERE id = ?";
