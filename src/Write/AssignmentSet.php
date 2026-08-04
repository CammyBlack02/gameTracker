<?php

namespace GameTracker\Write;

use GameTracker\Cli\Context;
use GameTracker\Cli\UsageException;

/**
 * The validated set of column assignments for one write.
 *
 * Parses --set-<column>[=value] and --clear-<column> against a WriteDefinition.
 * Values are always bound; only column names from the definition are ever
 * interpolated, and they are backticked because `condition` is a reserved word.
 */
final class AssignmentSet
{
    private function __construct(
        /** @var array<string, string|int|null> column => value */
        public readonly array $columns,
    ) {
    }

    public static function parse(WriteDefinition $def, Context $ctx): self
    {
        $assignments = [];

        foreach ($ctx->options as $key => $raw) {
            if (str_starts_with($key, 'set-')) {
                $column = substr($key, 4);
                self::assertWritable($def, $column, $key);

                // A bare flag arrives as true. "set title to true" is never
                // intended, so it is only meaningful on the boolean columns.
                if ($raw === true) {
                    if (!$def->isBoolean($column)) {
                        throw new UsageException(
                            "--set-{$column} needs a value (e.g. --set-{$column}=…)"
                        );
                    }
                    $value = 1;
                } else {
                    $value = (string)$raw;
                }
            } elseif (str_starts_with($key, 'clear-')) {
                $column = substr($key, 6);
                self::assertWritable($def, $column, $key);

                if (!$def->isNullable($column)) {
                    throw new UsageException(
                        "{$column} cannot be cleared — it is NOT NULL on {$def->table}"
                    );
                }

                $value = null;
            } else {
                continue;
            }

            if (array_key_exists($column, $assignments)) {
                throw new UsageException(
                    "{$column} is assigned twice — pass either --set-{$column} or --clear-{$column}, not both"
                );
            }

            $assignments[$column] = $value;
        }

        return new self($assignments);
    }

    public function isEmpty(): bool
    {
        return $this->columns === [];
    }

    /**
     * The SET clause body, e.g. "`genre` = ?, `played` = ?".
     */
    public function setSql(): string
    {
        return implode(', ', array_map(
            static fn(string $c): string => '`' . $c . '` = ?',
            array_keys($this->columns)
        ));
    }

    /**
     * @return list<string|int|null> in the same order as setSql()
     */
    public function params(): array
    {
        return array_values($this->columns);
    }

    /**
     * Required columns this assignment set does not supply.
     *
     * NOT NULL columns with no database default have to arrive with the insert;
     * letting MySQL reject it instead would surface a driver message rather
     * than a usage error naming the column.
     *
     * A column assigned NULL via --clear- counts as missing: --clear-title is
     * already rejected for NOT NULL columns, but a resource whose required
     * column is nullable would otherwise slip through.
     *
     * @return list<string>
     */
    public function missingRequired(WriteDefinition $def): array
    {
        $missing = [];

        foreach ($def->requiredOnCreate as $column) {
            if (!array_key_exists($column, $this->columns) || $this->columns[$column] === null) {
                $missing[] = $column;
            }
        }

        return $missing;
    }

    /**
     * The column list for an insert, e.g. "`title`, `platform`".
     *
     * The statement itself is assembled in src/Services/Write/ — the read-only
     * guard greps all of src/ for write keywords and permits them only there.
     */
    public function columnListSql(): string
    {
        return implode(', ', array_map(
            static fn(string $c): string => '`' . $c . '`',
            array_keys($this->columns)
        ));
    }

    /**
     * Placeholders matching columnListSql(), e.g. "?, ?".
     */
    public function placeholders(): string
    {
        return implode(', ', array_fill(0, count($this->columns), '?'));
    }

    /**
     * Assignments in a form suitable for output, with null preserved so a
     * cleared column is distinguishable from an empty string.
     */
    public function describe(): array
    {
        return $this->columns;
    }

    private static function assertWritable(WriteDefinition $def, string $column, string $flag): void
    {
        if ($column === '') {
            throw new UsageException("--{$flag} is missing a column name");
        }

        if (!$def->isWritable($column)) {
            throw new UsageException(
                "{$column} is not a writable column on {$def->table}. "
                . 'Available: ' . implode(', ', $def->writable)
            );
        }
    }
}
