<?php

namespace GameTracker\Write;

/**
 * Declares which columns a resource permits writing, and how.
 *
 * Mirrors FilterDefinition: every column name that reaches SQL comes from here
 * rather than from user input, so a write cannot touch a column the resource
 * did not offer — id, user_id, created_at and updated_at are absent from every
 * writable list by design.
 */
final class WriteDefinition
{
    public function __construct(
        public readonly string $table,
        /** @var list<string> columns that may be assigned */
        public readonly array $writable,
        /** @var list<string> columns where a bare --set-<col> means 1 */
        public readonly array $booleans,
        /** @var list<string> columns that cannot be set to NULL */
        public readonly array $notNull,
        /** @var list<string> columns create() must be given */
        public readonly array $requiredOnCreate,
    ) {
    }

    public function isWritable(string $column): bool
    {
        return in_array($column, $this->writable, true);
    }

    public function isBoolean(string $column): bool
    {
        return in_array($column, $this->booleans, true);
    }

    public function isNullable(string $column): bool
    {
        return !in_array($column, $this->notNull, true);
    }

    /**
     * Every --set-/--clear- flag this resource accepts, for allowedOptions().
     * Derived so adding a writable column cannot forget to allow its flags.
     *
     * @return list<string>
     */
    public function flagNames(): array
    {
        $names = [];

        foreach ($this->writable as $column) {
            $names[] = 'set-' . $column;
            // clear- is allowed for every writable column, including NOT NULL
            // ones. Rejecting --clear-title here would surface the generic
            // "unknown option" message; letting it through means AssignmentSet
            // can say why it is impossible, which is the more useful failure.
            $names[] = 'clear-' . $column;
        }

        return $names;
    }
}
