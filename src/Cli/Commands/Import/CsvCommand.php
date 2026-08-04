<?php

namespace GameTracker\Cli\Commands\Import;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
use GameTracker\Cli\UserResolver;
use GameTracker\Import\CsvProfile;
use GameTracker\Import\CsvSource;
use GameTracker\Journal\JournalWriter;
use GameTracker\Services\Write\CoverFetcher;
use GameTracker\Services\Write\Importer;

/**
 * Imports a CSV.
 *
 * Always previews without --yes: an import is bulk by nature, so the
 * blast-radius rule applies unconditionally rather than by row count.
 */
final class CsvCommand implements Command
{
    public const NAME = 'import csv';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Import games and items from a CSV file';
    }

    public static function allowedOptions(): array
    {
        return ['profile', 'map', 'yes'];
    }

    public function run(array $args, Context $ctx): int
    {
        $path = $args[0] ?? null;
        if ($path === null) {
            throw new UsageException(
                'usage: gt import csv <file> [--profile=<name>|--map=<col>:<header>,…]'
            );
        }

        $profileName = $ctx->option('profile');
        $map = $ctx->option('map');

        if ($profileName !== null && $map !== null) {
            throw new UsageException('pass either --profile or --map, not both');
        }

        $profile = $map !== null
            ? CsvProfile::fromMap(self::parseMap($map))
            : CsvProfile::named($profileName ?? 'gameeye');

        $source = new CsvSource($path, $profile);

        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $userId = (int)$user['id'];

        $plan = Importer::plan($ctx->pdo, $userId, $source);

        if (!$ctx->flag('yes')) {
            return $this->preview($ctx, $plan, $source->describe());
        }

        $result = Importer::apply(
            $ctx->pdo,
            $userId,
            $plan['candidates'],
            new JournalWriter(),
            array_slice($_SERVER['argv'] ?? [], 1)
        );

        // Covers are fetched only after the transaction has committed: holding
        // row locks through hundreds of HTTP requests would be far worse than
        // a cover arriving a moment late.
        $covers = CoverFetcher::fetchAll($ctx->pdo, $userId, $result['ids']);

        return $this->report($ctx, $plan, $result, $covers);
    }

    /**
     * @return array<string,string> target column => CSV header
     */
    private static function parseMap(string $raw): array
    {
        $map = [];

        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            if (!str_contains($pair, ':')) {
                throw new UsageException("--map entry '{$pair}' is not <column>:<header>");
            }
            [$column, $header] = explode(':', $pair, 2);
            $column = trim($column);
            $header = trim($header);
            if ($column === '' || $header === '') {
                throw new UsageException("--map entry '{$pair}' has an empty side");
            }
            $map[$column] = $header;
        }

        return $map;
    }

    private function preview(Context $ctx, array $plan, string $describe): int
    {
        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line("would import from {$describe}");
            $ctx->output->line(sprintf(
                '  %d new (%d games, %d items), %d already owned, %d skipped',
                count($plan['candidates']),
                $plan['byTable']['games'] ?? 0,
                $plan['byTable']['items'] ?? 0,
                $plan['matched'],
                $plan['skipped']
            ));
            $ctx->output->line('re-run with --yes to apply');

            return 0;
        }

        $ctx->output->record([
            'dry_run' => true,
            'source' => $describe,
            'would_insert' => count($plan['candidates']),
            'by_table' => $plan['byTable'],
            'matched' => $plan['matched'],
            'skipped' => $plan['skipped'],
        ]);

        return 0;
    }

    private function report(Context $ctx, array $plan, array $result, array $covers): int
    {
        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line(sprintf(
                'imported %d, already owned %d, skipped %d',
                $result['inserted'],
                $plan['matched'],
                $plan['skipped']
            ));
            if ($covers['failed'] > 0) {
                $ctx->output->warn(sprintf(
                    '%d cover downloads failed — rows imported without a cover',
                    $covers['failed']
                ));
            }
            if ($result['journal_id'] !== null) {
                $ctx->output->line('undo with: gt undo ' . $result['journal_id'] . ' --yes');
            }

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'inserted' => $result['inserted'],
            'matched' => $plan['matched'],
            'skipped' => $plan['skipped'],
            'covers_fetched' => $covers['fetched'],
            'covers_failed' => $covers['failed'],
            'journal_id' => $result['journal_id'],
        ]);

        return 0;
    }
}
