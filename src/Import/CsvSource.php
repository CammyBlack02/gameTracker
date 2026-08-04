<?php

namespace GameTracker\Import;

use GameTracker\Cli\UsageException;

/**
 * Reads a CSV and emits ImportRows through a CsvProfile.
 *
 * Headers are matched by name, not position, so a column order change in an
 * export does not silently shift every value one field to the left.
 */
final class CsvSource implements Source
{
    private int $skipped = 0;
    /** @var array<string,int> reason => count */
    private array $skippedReasons = [];
    private int $records = 0;

    public function __construct(
        private readonly string $path,
        private readonly CsvProfile $profile,
    ) {
        if (!is_file($this->path) || !is_readable($this->path)) {
            throw new UsageException("cannot read CSV file '{$this->path}'");
        }
    }

    public function describe(): string
    {
        return "{$this->profile->name} CSV ({$this->records} records read)";
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    /** @return array<string,int> */
    public function skippedReasons(): array
    {
        return $this->skippedReasons;
    }

    public function rows(): iterable
    {
        $handle = fopen($this->path, 'r');
        if ($handle === false) {
            throw new UsageException("cannot open CSV file '{$this->path}'");
        }

        try {
            $headers = fgetcsv($handle);
            if ($headers === false || $headers === [null]) {
                throw new UsageException('CSV has no header row');
            }
            $headers = array_map(static fn($h): string => trim((string)$h), $headers);

            $missing = array_diff(array_values($this->profile->columns), $headers);
            if ($missing !== []) {
                throw new UsageException(
                    'CSV is missing columns the ' . $this->profile->name
                    . ' profile needs: ' . implode(', ', $missing)
                );
            }

            $index = array_flip($headers);
            $this->skipped = 0;
            $this->skippedReasons = [];
            $this->records = 0;

            while (($record = fgetcsv($handle)) !== false) {
                if ($record === [null]) {
                    continue; // blank line
                }
                $this->records++;

                $assoc = [];
                foreach ($index as $header => $position) {
                    $assoc[$header] = $record[$position] ?? null;
                }

                $route = $this->profile->route($assoc);
                if ($route === null) {
                    $this->skipped++;
                    $reason = trim((string)($assoc['Category'] ?? 'unroutable'));
                    $reason = $reason === '' ? 'unroutable' : $reason;
                    $this->skippedReasons[$reason] = ($this->skippedReasons[$reason] ?? 0) + 1;
                    continue;
                }

                $columns = $route['extra'];
                foreach ($this->profile->columns as $column => $header) {
                    $value = $assoc[$header] ?? null;
                    $value = $value === null ? null : trim((string)$value);
                    if ($value === null || $value === '') {
                        continue; // absent, not empty-string
                    }
                    $columns[$column] = $value;
                }

                if (($columns['title'] ?? '') === '') {
                    $this->skipped++;
                    $this->skippedReasons['no title'] = ($this->skippedReasons['no title'] ?? 0) + 1;
                    continue;
                }

                yield new ImportRow($route['table'], $columns);
            }
        } finally {
            fclose($handle);
        }
    }
}
