<?php

namespace GameTracker\Journal;

/**
 * One reversible operation.
 *
 * `rows` holds a snapshot per affected row: its id, its updated_at at the time
 * of the write, and the before-values of the columns that changed. The
 * updated_at is what lets undo detect that something else has modified the row
 * since, instead of silently discarding that newer edit.
 */
final class JournalEntry
{
    public function __construct(
        public readonly string $id,
        public readonly array $argv,
        public readonly int $userId,
        public readonly string $resource,
        public readonly string $operation,
        public readonly bool $committed,
        public readonly ?string $revertedAt,
        /** @var list<array{id:int, updated_at:?string, before:array}> */
        public readonly array $rows,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'argv' => $this->argv,
            'user_id' => $this->userId,
            'resource' => $this->resource,
            'operation' => $this->operation,
            'committed' => $this->committed,
            'reverted_at' => $this->revertedAt,
            'rows' => $this->rows,
        ];
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            $data['argv'] ?? [],
            (int)$data['user_id'],
            $data['resource'],
            $data['operation'],
            (bool)($data['committed'] ?? false),
            $data['reverted_at'] ?? null,
            $data['rows'] ?? [],
        );
    }

    public function isRevertable(): bool
    {
        return $this->committed && $this->revertedAt === null;
    }
}
