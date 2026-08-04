<?php

namespace GameTracker\Cli\Commands\Import;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Import\CsvProfile;

/**
 * Lists the built-in CSV profiles. Read-only.
 */
final class ProfilesCommand implements Command
{
    public const NAME = 'import profiles';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'List the built-in CSV import profiles';
    }

    public static function allowedOptions(): array
    {
        return [];
    }

    public function run(array $args, Context $ctx): int
    {
        $rows = [];

        foreach (CsvProfile::names() as $name) {
            $profile = CsvProfile::named($name);
            $rows[] = [
                'profile' => $name,
                'columns' => implode(', ', array_map(
                    static fn(string $col, string $header): string => "{$col}<={$header}",
                    array_keys($profile->columns),
                    array_values($profile->columns)
                )),
                'skips' => implode(', ', $profile->skippedCategories),
            ];
        }

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->rows($rows);

            return 0;
        }

        $ctx->output->record(['profiles' => $rows]);

        return 0;
    }
}
