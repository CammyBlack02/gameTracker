<?php

namespace GameTracker\Cli\Commands\Import;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UserResolver;
use GameTracker\Import\CurlTransport;
use GameTracker\Import\SteamSource;
use GameTracker\Journal\JournalWriter;
use GameTracker\Services\Write\CoverFetcher;
use GameTracker\Services\Write\Importer;

/**
 * Imports the user's Steam library.
 */
final class SteamCommand implements Command
{
    public const NAME = 'import steam';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Import owned games from Steam';
    }

    public static function allowedOptions(): array
    {
        return ['yes'];
    }

    public function run(array $args, Context $ctx): int
    {
        $user = UserResolver::resolve($ctx->pdo, $ctx->userRef);
        $userId = (int)$user['id'];

        $credentials = SteamSource::credentialsFor($ctx->pdo, $userId);
        $source = new SteamSource(new CurlTransport(), $credentials['key'], $credentials['id']);

        $plan = Importer::plan($ctx->pdo, $userId, $source);

        if (!$ctx->flag('yes')) {
            if ($ctx->output->format() === Output::FORMAT_TABLE) {
                $ctx->output->line(sprintf(
                    'would import %d new games, %d already owned',
                    count($plan['candidates']),
                    $plan['matched']
                ));
                $ctx->output->line('re-run with --yes to apply');

                return 0;
            }

            $ctx->output->record([
                'dry_run' => true,
                'would_insert' => count($plan['candidates']),
                'matched' => $plan['matched'],
            ]);

            return 0;
        }

        $result = Importer::apply(
            $ctx->pdo,
            $userId,
            $plan['candidates'],
            new JournalWriter(),
            array_slice($_SERVER['argv'] ?? [], 1)
        );

        $covers = CoverFetcher::fetchAll($ctx->pdo, $userId, $result['ids']);

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line(sprintf(
                'imported %d, already owned %d, covers %d/%d',
                $result['inserted'],
                $plan['matched'],
                $covers['fetched'],
                $covers['fetched'] + $covers['failed']
            ));
            if ($result['journal_id'] !== null) {
                $ctx->output->line('undo with: gt undo ' . $result['journal_id'] . ' --yes');
            }

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'inserted' => $result['inserted'],
            'matched' => $plan['matched'],
            'covers_fetched' => $covers['fetched'],
            'covers_failed' => $covers['failed'],
            'journal_id' => $result['journal_id'],
        ]);

        return 0;
    }
}
