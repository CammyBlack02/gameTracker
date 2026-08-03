<?php

namespace GameTracker\Cli;

/**
 * Renders command results as either JSON or an aligned text table.
 *
 * JSON is the default because the primary consumer is an agent parsing the
 * output; --table is for human eyes. Everything a command wants to show goes
 * through here so the two formats can never drift per-command.
 */
final class Output
{
    public const FORMAT_JSON = 'json';
    public const FORMAT_TABLE = 'table';

    public function __construct(private readonly string $format = self::FORMAT_JSON)
    {
    }

    public function format(): string
    {
        return $this->format;
    }

    /**
     * A single record: flat key/value pairs.
     */
    public function record(array $data): void
    {
        if ($this->format === self::FORMAT_JSON) {
            $this->json($data);
            return;
        }

        $width = 0;
        foreach (array_keys($data) as $key) {
            $width = max($width, strlen((string)$key));
        }

        foreach ($data as $key => $value) {
            printf("%-{$width}s  %s\n", $key, $this->scalar($value));
        }
    }

    /**
     * A list of records, rendered as a table with a header row when the format
     * is table. Column order comes from the first row's keys.
     */
    public function rows(array $rows, ?array $columns = null): void
    {
        if ($this->format === self::FORMAT_JSON) {
            $this->json($rows);
            return;
        }

        if ($rows === []) {
            fwrite(STDOUT, "(no rows)\n");
            return;
        }

        $columns ??= array_keys($rows[0]);

        $widths = [];
        foreach ($columns as $col) {
            $widths[$col] = strlen((string)$col);
        }
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $widths[$col] = max($widths[$col], strlen($this->scalar($row[$col] ?? null)));
            }
        }

        $line = '';
        foreach ($columns as $col) {
            $line .= sprintf("%-{$widths[$col]}s  ", $col);
        }
        fwrite(STDOUT, rtrim($line) . "\n");

        $rule = '';
        foreach ($columns as $col) {
            $rule .= str_repeat('-', $widths[$col]) . '  ';
        }
        fwrite(STDOUT, rtrim($rule) . "\n");

        foreach ($rows as $row) {
            $line = '';
            foreach ($columns as $col) {
                $line .= sprintf("%-{$widths[$col]}s  ", $this->scalar($row[$col] ?? null));
            }
            fwrite(STDOUT, rtrim($line) . "\n");
        }
    }

    /**
     * Advisory message. Goes to STDERR so it never contaminates JSON on STDOUT
     * that a caller may be piping into jq.
     */
    public function warn(string $message): void
    {
        fwrite(STDERR, "warning: {$message}\n");
    }

    public function error(string $message): void
    {
        fwrite(STDERR, "error: {$message}\n");
    }

    public function line(string $message): void
    {
        fwrite(STDOUT, $message . "\n");
    }

    private function json(mixed $data): void
    {
        fwrite(STDOUT, json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ) . "\n");
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '-',
            is_bool($value) => $value ? 'yes' : 'no',
            is_array($value) => json_encode($value, JSON_UNESCAPED_SLASHES),
            default => (string)$value,
        };
    }
}
