<?php

namespace GameTracker\Cli\Commands\Images;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use GameTracker\Cli\Output;
use GameTracker\Cli\UsageException;
use GameTracker\Images\ImageIndex;
use GameTracker\Images\Reconciler;
use GameTracker\Services\Write\Trash;
use RuntimeException;

/**
 * Moves unreferenced image files to trash.
 *
 * Never unlinks. See Trash for why: this is the least recoverable operation in
 * the system, because a database restore brings back rows and not files.
 */
final class PruneCommand implements Command
{
    public const NAME = 'images prune';

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Move unreferenced image files to trash (never deletes)';
    }

    public static function allowedOptions(): array
    {
        return ['yes', 'restore', 'list'];
    }

    public function run(array $args, Context $ctx): int
    {
        $trash = new Trash();
        $uploads = ImageIndex::uploadsDir();

        if ($ctx->flag('list')) {
            return $this->listBatches($ctx, $trash);
        }

        $restoreId = $ctx->option('restore');
        if ($restoreId !== null) {
            return $this->restore($ctx, $trash, $uploads, $restoreId);
        }

        // Not user-scoped: a file is only an orphan if NO row anywhere
        // references it. Scoping would offer another user's files for deletion.
        $referenced = ImageIndex::referencedFor($ctx->pdo, null);
        $disk = ImageIndex::onDisk($uploads);

        $result = Reconciler::reconcile(
            $referenced['filenames'],
            $disk['sources'],
            $disk['thumbs']
        );

        // The same guard the audit applies. Pruning on a wrong-directory
        // mismatch would move every live file to trash, so refuse outright
        // rather than merely warning.
        $referencedCount = count($referenced['filenames']);
        if ($referencedCount > 20 && count($result['missing']) > ($referencedCount / 2)) {
            $ctx->output->error(sprintf(
                'refusing to prune: more than half of the %d referenced files are absent '
                . 'from %s, which usually means this uploads directory does not belong to '
                . 'this database. Run `gt images audit` and resolve that first.',
                $referencedCount,
                $uploads
            ));

            return 1;
        }

        $files = $this->pathsFor($uploads, $result['orphans'], $result['prunableThumbs']);

        if ($files === []) {
            if ($ctx->output->format() === Output::FORMAT_TABLE) {
                $ctx->output->line('nothing to prune');

                return 0;
            }

            $ctx->output->record(['dry_run' => false, 'moved' => 0, 'trash_id' => null]);

            return 0;
        }

        if (!$ctx->flag('yes')) {
            if ($ctx->output->format() === Output::FORMAT_TABLE) {
                $ctx->output->line(sprintf(
                    'would move %d files (%d orphans + %d thumbnails) to %s',
                    count($files),
                    count($result['orphans']),
                    count($result['prunableThumbs']),
                    $trash->dir()
                ));
                $ctx->output->line('re-run with --yes to apply');

                return 0;
            }

            $ctx->output->record([
                'dry_run' => true,
                'would_move' => count($files),
                'orphans' => count($result['orphans']),
                'thumbnails' => count($result['prunableThumbs']),
                'trash_dir' => $trash->dir(),
            ]);

            return 0;
        }

        $moved = $trash->move($files, $uploads);

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line(sprintf(
                'moved %d files to %s/%s (%d failed)',
                $moved['moved'],
                $trash->dir(),
                $moved['id'],
                $moved['failed']
            ));
            $ctx->output->line('restore with: gt images prune --restore=' . $moved['id']);

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'moved' => $moved['moved'],
            'failed' => $moved['failed'],
            'trash_id' => $moved['id'],
            'trash_dir' => $trash->dir(),
        ]);

        return 0;
    }

    /**
     * Orphan sources and prunable thumbnails as paths relative to uploads.
     *
     * @param list<string> $orphans
     * @param list<string> $thumbs
     * @return list<string>
     */
    private function pathsFor(string $uploads, array $orphans, array $thumbs): array
    {
        $paths = [];

        foreach (['covers', 'extras'] as $sub) {
            foreach ($orphans as $file) {
                if (is_file($uploads . '/' . $sub . '/' . $file)) {
                    $paths[] = $sub . '/' . $file;
                }
            }
            foreach ($thumbs as $file) {
                if (is_file($uploads . '/' . $sub . '/thumbs/' . $file)) {
                    $paths[] = $sub . '/thumbs/' . $file;
                }
            }
        }

        return $paths;
    }

    private function restore(Context $ctx, Trash $trash, string $uploads, string $id): int
    {
        try {
            $result = $trash->restore($id, $uploads);
        } catch (RuntimeException $e) {
            throw new UsageException($e->getMessage());
        }

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->line(sprintf(
                'restored %d, skipped %d',
                $result['restored'],
                $result['skipped']
            ));

            return 0;
        }

        $ctx->output->record([
            'dry_run' => false,
            'restored' => $result['restored'],
            'skipped' => $result['skipped'],
        ]);

        return 0;
    }

    private function listBatches(Context $ctx, Trash $trash): int
    {
        $batches = $trash->batches();

        if ($ctx->output->format() === Output::FORMAT_TABLE) {
            $ctx->output->rows($batches);

            return 0;
        }

        $ctx->output->record(['trash_dir' => $trash->dir(), 'batches' => $batches]);

        return 0;
    }
}
