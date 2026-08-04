<?php

namespace GameTracker\Cli\Commands\Images;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Images\ImageIndex;
use GameTracker\Images\Reconciler;

/**
 * Reports how image references and files on disk line up. Read-only.
 *
 * Replaces scripts/audit-images.sh: two things computing the same numbers by
 * different routes is how they come to disagree, which is exactly what happened
 * between the 2026-08-03 note and the 2026-08-04 re-measurement.
 */
final class AuditCommand implements Command
{
    public const NAME = 'images audit';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Report image storage modes, orphan files and broken references';
    }

    public static function allowedOptions(): array
    {
        return [];
    }

    public function run(array $args, Context $ctx): int
    {
        // Deliberately not user-scoped: a file is only an orphan if NO row
        // anywhere references it. Scoping this to one user would report another
        // user's files as prunable.
        $referenced = ImageIndex::referencedFor($ctx->pdo, null);

        $uploads = ImageIndex::uploadsDir();
        $disk = ImageIndex::onDisk($uploads);

        $result = Reconciler::reconcile(
            $referenced['filenames'],
            $disk['sources'],
            $disk['thumbs']
        );

        $payload = [
            'uploads_dir' => $uploads,
            'by_mode' => $referenced['byMode'],
            'referenced_files' => count($referenced['filenames']),
            'on_disk' => count($disk['sources']),
            'orphans' => count($result['orphans']),
            'missing' => count($result['missing']),
            'thumbnails' => [
                'total' => count($disk['thumbs']),
                'kept' => $result['keptThumbs'],
                'prunable' => count($result['prunableThumbs']),
            ],
        ];

        // If most referenced files are absent, the far likelier explanation is
        // that this is pointed at the wrong uploads directory than that the
        // collection has genuinely evaporated. Say so loudly: prune consumes
        // these numbers, and acting on a mismatch would move live files to
        // trash.
        $referencedCount = count($referenced['filenames']);
        $payload['suspect_mismatch'] = $referencedCount > 20
            && count($result['missing']) > ($referencedCount / 2);

        if ($payload['suspect_mismatch']) {
            $ctx->output->warn(sprintf(
                'more than half of referenced files are absent from %s — this usually '
                . 'means the uploads directory does not belong to this database. '
                . 'Do NOT prune on these numbers.',
                $payload['uploads_dir']
            ));
        }

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line("uploads: {$payload['uploads_dir']}");
            $ctx->output->line(sprintf(
                'referenced %d, on disk %d, orphans %d, missing %d',
                $payload['referenced_files'],
                $payload['on_disk'],
                $payload['orphans'],
                $payload['missing']
            ));
            $ctx->output->line(sprintf(
                'thumbnails %d (keep %d, prunable %d)',
                $payload['thumbnails']['total'],
                $payload['thumbnails']['kept'],
                $payload['thumbnails']['prunable']
            ));

            $rows = [];
            foreach ($payload['by_mode'] as $column => $counts) {
                $rows[] = [
                    'column' => $column,
                    'data-uri' => $counts['data-uri'],
                    'url' => $counts['url'],
                    'filename' => $counts['filename'],
                ];
            }
            $ctx->output->rows($rows);

            return 0;
        }

        $ctx->output->record($payload);

        return 0;
    }
}
