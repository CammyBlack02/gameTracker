<?php

namespace GameTracker\Journal;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Reads and writes journal entries as one JSON file per operation.
 *
 * The directory lives outside the repository on purpose: everything under
 * /var/www/gameTracker is inside the nginx document root and therefore
 * fetchable over HTTP, and a journal of collection rows does not belong there.
 * GT_JOURNAL_DIR overrides it, which is how tests avoid the real journal.
 */
final class JournalWriter
{
    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        $dir ??= getenv('GT_JOURNAL_DIR') ?: null;

        if ($dir === null) {
            $home = getenv('HOME');
            if ($home === false || $home === '') {
                throw new RuntimeException('cannot locate a home directory for the journal');
            }
            $dir = $home . '/.gt/journal';
        }

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create journal directory {$dir}");
        }

        $this->dir = $dir;
    }

    public function dir(): string
    {
        return $this->dir;
    }

    /**
     * A fresh, sortable, collision-free id for an operation.
     *
     * Microsecond precision is load-bearing, not decorative: two writes inside
     * the same second would otherwise produce the same id and the second would
     * overwrite the first entry, silently destroying undo history. The format
     * stays lexicographically sortable so recent() can just reverse-sort
     * filenames.
     */
    public function newId(string $operation): string
    {
        $stamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format('Y-m-d\TH-i-s-u\Z');

        return $stamp . '-' . $operation;
    }

    public function write(JournalEntry $entry): string
    {
        $this->put($entry);

        return $entry->id;
    }

    public function markCommitted(string $id): void
    {
        $entry = $this->read($id);

        $this->put(new JournalEntry(
            $entry->id,
            $entry->argv,
            $entry->userId,
            $entry->resource,
            $entry->operation,
            true,
            $entry->revertedAt,
            $entry->rows,
        ));
    }

    public function markReverted(string $id): void
    {
        $entry = $this->read($id);

        $this->put(new JournalEntry(
            $entry->id,
            $entry->argv,
            $entry->userId,
            $entry->resource,
            $entry->operation,
            $entry->committed,
            gmdate('c'),
            $entry->rows,
        ));
    }

    public function read(string $id): JournalEntry
    {
        $path = $this->pathFor($id);

        if (!is_file($path)) {
            throw new RuntimeException("no journal entry {$id}");
        }

        $data = json_decode((string)file_get_contents($path), true);

        if (!is_array($data)) {
            throw new RuntimeException("journal entry {$id} is not readable JSON");
        }

        return JournalEntry::fromArray($data);
    }

    /**
     * Newest first. Filenames begin with a UTC timestamp, so a reverse
     * lexicographic sort is chronological.
     *
     * @return list<JournalEntry>
     */
    public function recent(int $limit = 20): array
    {
        $files = glob($this->dir . '/*.json') ?: [];
        rsort($files);

        $entries = [];
        foreach (array_slice($files, 0, $limit) as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if (is_array($data)) {
                $entries[] = JournalEntry::fromArray($data);
            }
        }

        return $entries;
    }

    public function latestRevertable(): ?JournalEntry
    {
        foreach ($this->recent(100) as $entry) {
            if ($entry->isRevertable()) {
                return $entry;
            }
        }

        return null;
    }

    private function put(JournalEntry $entry): void
    {
        $path = $this->pathFor($entry->id);
        $json = json_encode(
            $entry->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if (file_put_contents($path, $json . "\n") === false) {
            throw new RuntimeException("cannot write journal entry to {$path}");
        }

        @chmod($path, 0600);
    }

    private function pathFor(string $id): string
    {
        // Ids are generated internally, but they end up in a filesystem path,
        // so refuse anything that could escape the journal directory.
        if (!preg_match('/^[0-9A-Za-z:\-]+$/', $id)) {
            throw new RuntimeException("invalid journal id '{$id}'");
        }

        return $this->dir . '/' . $id . '.json';
    }
}
