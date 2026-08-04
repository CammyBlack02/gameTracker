<?php

namespace GameTracker\Cli\Commands;

use GameTracker\Cli\Command;
use GameTracker\Cli\Context;
use PDO;
use Throwable;

/**
 * Read-only SQL escape hatch, for questions the structured commands cannot ask.
 *
 * READS ONLY, deliberately and permanently. The governing constraint on this CLI
 * is that breaking the website is acceptable but altering the games data is not,
 * and a raw write would bypass every mechanism the write layer exists to provide:
 * journalling, `gt undo`, the deletion tombstones iOS syncs against, and the
 * `updated_at` bump that makes a change visible to the phone at all. If you need
 * to mutate outside the structured commands, use `mysql` and know you are outside
 * the safety rails. There is no --write flag and adding one would be a mistake.
 *
 * Enforcement is three independent layers, because each covers a gap in the
 * others. All three were verified empirically against MySQL 8.0.45 rather than
 * assumed:
 *
 *   1. NATIVE PREPARED STATEMENT. PDO here has ATTR_EMULATE_PREPARES on by
 *      default, so `query()` will happily run a SELECT followed by a second
 *      statement that destroys a table — confirmed by losing a probe table that
 *      way. A native prepare rejects the second statement outright, closing the
 *      whole "append a statement" class structurally rather than by parsing,
 *      which is where hand-written quote-aware scanners go wrong.
 *   2. LEADING-KEYWORD ALLOWLIST. This is what stops DDL, and it has to, because
 *      layer 3 cannot: DDL forces an implicit commit that ends the read-only
 *      transaction before executing. A table-destroying statement appended to
 *      `START TRANSACTION READ ONLY` succeeds with no error at all.
 *   3. READ ONLY TRANSACTION, always rolled back. The server itself refuses
 *      INSERT and UPDATE inside it (error 1792), so any DML that somehow got past
 *      layer 2 still cannot commit. The rollback is unconditional, so even a
 *      SELECT that acquired locks releases them.
 *
 * Not user-scoped, like `db info` and `doctor`: this is an operator tool, and
 * whoever can run it already holds the database credentials.
 */
final class SqlCommand implements Command
{
    public const NAME = 'sql';

    /**
     * Statements that only read. `WITH` is included for CTEs, which is why layer
     * 1 matters — `WITH x AS (...) DELETE ...` is valid MySQL, so the keyword
     * alone is not proof of a read and the read-only transaction is what actually
     * stops it.
     */
    private const READ_ONLY_LEADERS = ['SELECT', 'SHOW', 'EXPLAIN', 'DESCRIBE', 'DESC', 'WITH'];

    private const DEFAULT_LIMIT = 200;

    public static function name(): string
    {
        return self::NAME;
    }

    public static function description(): string
    {
        return 'Run a read-only SQL query (SELECT/SHOW/EXPLAIN/DESCRIBE only)';
    }

    public static function allowedOptions(): array
    {
        return ['limit'];
    }

    public function run(array $args, Context $ctx): int
    {
        if (count($args) !== 1 || trim($args[0]) === '') {
            $ctx->output->error('usage: gt sql "<query>" [--limit=N]');
            return 2;
        }

        $sql = trim($args[0]);

        // A single trailing semicolon is normal typing; strip it so it does not
        // reach the prepare and get rejected as an empty second statement.
        $sql = rtrim($sql, "; \t\n\r");

        $leader = $this->leadingKeyword($sql);

        if ($leader === null || !in_array($leader, self::READ_ONLY_LEADERS, true)) {
            $ctx->output->error(sprintf(
                'refused: gt sql runs reads only, and this starts with %s. Allowed: %s. '
                . 'Use the write commands (gt games set|create|delete) so the change is '
                . 'journalled and undoable, or mysql if you mean to bypass that.',
                $leader === null ? 'nothing recognisable' : $leader,
                implode(', ', self::READ_ONLY_LEADERS)
            ));
            return 2;
        }

        $limit = $ctx->intOption('limit', self::DEFAULT_LIMIT);
        if ($limit < 1) {
            $ctx->output->error('--limit must be 1 or more');
            return 2;
        }

        // Layer 1: a native prepare refuses a second statement. Restored
        // afterwards so nothing else in the process sees a changed connection.
        $emulate = $ctx->pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        $ctx->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        $inTransaction = false;

        try {
            // Layer 3. Not beginTransaction(), which cannot request READ ONLY.
            $ctx->pdo->exec('START TRANSACTION READ ONLY');
            $inTransaction = true;

            $stmt = $ctx->pdo->prepare($sql);
            $stmt->execute();

            $rows = [];
            $truncated = false;

            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                if (count($rows) >= $limit) {
                    $truncated = true;
                    break;
                }
                $rows[] = $row;
            }

            $stmt->closeCursor();

            $ctx->output->rows($rows);

            // On STDERR, so JSON on STDOUT stays pipeable. Saying so matters:
            // silently capping a result set is how someone concludes a table has
            // 200 rows in it.
            if ($truncated) {
                $ctx->output->warn(sprintf(
                    'output capped at %d rows — pass --limit to raise it, or add a COUNT(*) '
                    . 'or LIMIT to the query itself',
                    $limit
                ));
            }

            return 0;
        } catch (Throwable $e) {
            // The message is the server's own and goes to STDERR. This is an
            // operator tool on a terminal, not an HTTP response, so the rule
            // about not leaking exception detail into response bodies does not
            // apply — and a query tool that hides the syntax error is useless.
            $ctx->output->error('query failed: ' . $e->getMessage());
            return 1;
        } finally {
            if ($inTransaction) {
                // Unconditional. Nothing here is ever meant to commit, and a
                // read-only transaction left open holds a read view.
                try {
                    $ctx->pdo->exec('ROLLBACK');
                } catch (Throwable) {
                    // A failed rollback cannot be usefully handled here and must
                    // not mask the original error.
                }
            }

            $ctx->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $emulate);
        }
    }

    /**
     * The first SQL keyword, skipping leading comments and whitespace.
     *
     * Comment stripping is not cosmetic. A statement that opens with a block
     * comment and then destroys a table presents no recognisable keyword to a
     * naive scan, and taking literally the first word would read the comment's
     * own contents as the verb. Both line comment forms and block comments are
     * skipped for that reason.
     */
    private function leadingKeyword(string $sql): ?string
    {
        $s = $sql;

        while ($s !== '') {
            $s = ltrim($s);

            if (str_starts_with($s, '--') || str_starts_with($s, '#')) {
                $nl = strpos($s, "\n");
                if ($nl === false) {
                    return null;
                }
                $s = substr($s, $nl + 1);
                continue;
            }

            if (str_starts_with($s, '/*')) {
                $end = strpos($s, '*/');
                if ($end === false) {
                    return null;
                }
                $s = substr($s, $end + 2);
                continue;
            }

            break;
        }

        if (!preg_match('/^\(*\s*([A-Za-z_]+)/', $s, $m)) {
            return null;
        }

        return strtoupper($m[1]);
    }
}
