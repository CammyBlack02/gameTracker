# `gt` CLI — Image Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the database being used as an image store, then reconcile the files on disk with the rows that reference them.

**Architecture:** One unit decides what a column is holding (`StorageMode`), one computes the reconciliation as pure logic (`Reconciler`), one moves files (`Trash`). The write-path fix lives in `api/games.php` beside the existing URL-localising branch, because that is where the bug is.

**Spec:** `docs/superpowers/specs/2026-08-04-gt-cli-images-design.md`

## Global Constraints

- **Governing constraint:** temporary website breakage is acceptable; losing the games data is not. Files are *less* recoverable than rows — a mysqldump restore brings back rows, not images.
- **Never unlink. Move to trash** (`~/.gt/trash/<timestamp>/`, outside the document root).
- **Branch on storage mode before touching the disk.** A value is `data:`, `http(s)`, or a filename; only the third lives on disk. Failing to branch is the bug that produced the wrong 2026-08-03 audit.
- **Keep a thumbnail if its source is referenced.** 1,089 of 1,187 thumbs have a referenced source; a naive sweep destroys all of them.
- Write SQL only under `src/Services/Write/`; `src/Images/` stays free of it.
- Exit codes: `0` ok, `1` domain, `2` usage, `3` bootstrap.
- Prune previews and requires `--yes`.
- Tests never touch `uploads/` — fixtures use a temporary tree.
- No live network in CI.

## Existing interfaces

| Symbol | Contract |
|---|---|
| `downloadExternalImage($url, ?int $gameId, string $type)` | `api/games.php:438`. Returns the **bare filename**, or `false`. Does SSRF-safe fetch, magic-byte validation, thumbnail. Called only at :595 and :607, **both in `updateGame`** |
| `createGame` | `api/games.php` ~:373-404. Inserts `$frontCover` **with no localisation at all** — the reason 51 rows hold `http` URLs |
| `gt_generate_thumbnail($src, $dest, $max)` / `gt_thumbnail_path($path)` | `includes/thumbnail.php`. Thumb lives at `<dir>/thumbs/<name>` |
| `generateUniqueFilename($name, $dir)` | `includes/functions.php:176` |
| `COVERS_DIR` / `EXTRAS_DIR` | `includes/config.php` |
| `scripts/migrate-base64-covers.php` | idempotent, dry-run default, `--execute` to apply, covers all six columns |

## Measured starting state (2026-08-04)

| | |
|---|---|
| Rows holding base64 | 42 front (47.8 MB) + 39 back (65.0 MB) = **~113 MB** |
| Largest single cover | 3.1 MB |
| Created after the May migration | 12 — it refills |
| Orphan files | 99 (51.4 MB) |
| Thumbnails | 1,187 — **1,089 must be kept**, 98 prunable |
| Rows pointing at absent files | 49 |
| External URL rows (must not read as broken) | 51 game covers + 58 item images |

---

### Task 1: Reject base64 at the write path

The bug fix. Everything else is cleanup that only stays clean because of this.

**Files:** Modify `api/games.php`; create `tests/v2/test_base64_rejected.sh`

**Interfaces:**
- Produces: `storeDataUriImage(string $dataUri, ?int $gameId, string $type): string|false` — mirrors `downloadExternalImage`'s contract exactly: bare filename on success, `false` on failure.

- [ ] **Step 1: Write the failing test**

Create `tests/v2/test_base64_rejected.sh`. It drives the v1 HTTP API the harness already boots:

```bash
#!/usr/bin/env bash
# A cover column may hold a filename or a URL. Never an image.
#
# api/games.php localised http(s) URLs but had no branch for data: URIs, so
# they were stored verbatim — 81 rows and ~113 MB of base64 by 2026-08-04, and
# still arriving after the May migration converted 542 of them.
source "$(dirname "$0")/lib.sh"

# A 1x1 red GIF, small enough to inline and a real decodable image.
TINY_GIF="data:image/gif;base64,R0lGODlhAQABAIAAAP8AAAAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw=="

login_as_test_user   # provided by lib.sh; sets $COOKIE_JAR

blue "createGame must not store a data URI"

CREATE=$(curl -s -b "$COOKIE_JAR" -X POST "$BASE_URL/api/games.php?action=create" \
  -H 'Content-Type: application/json' \
  -d "{\"title\":\"B64 Create Probe\",\"platform\":\"PC\",\"front_cover_image\":\"$TINY_GIF\"}")

NEW_ID=$(echo "$CREATE" | jq -r '.game_id // .id // empty')
[[ -n "$NEW_ID" ]] \
  && { green "  PASS: create succeeded"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: create did not return an id: $CREATE"; FAIL_COUNT=$((FAIL_COUNT+1)); }

STORED=$(test_mysql -N -e "SELECT LEFT(front_cover_image, 5) FROM games WHERE id = $NEW_ID")
assert_eq "" "$(echo "$STORED" | grep '^data:' || true)" "created row does not hold a data URI"

LEN=$(test_mysql -N -e "SELECT LENGTH(front_cover_image) FROM games WHERE id = $NEW_ID")
[[ "$LEN" -gt 0 && "$LEN" -lt 200 ]] \
  && { green "  PASS: stored a filename, not an image ($LEN bytes)"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: expected a short filename, got $LEN bytes"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "updateGame must not store a data URI either"

curl -s -b "$COOKIE_JAR" -X POST "$BASE_URL/api/games.php?action=update" \
  -H 'Content-Type: application/json' \
  -d "{\"id\":$NEW_ID,\"front_cover_image\":\"$TINY_GIF\"}" > /dev/null

LEN2=$(test_mysql -N -e "SELECT LENGTH(front_cover_image) FROM games WHERE id = $NEW_ID")
[[ "$LEN2" -lt 200 ]] \
  && { green "  PASS: update stored a filename"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: update stored $LEN2 bytes"; FAIL_COUNT=$((FAIL_COUNT+1)); }

blue "An undecodable data URI is rejected, not stored"

BAD=$(curl -s -b "$COOKIE_JAR" -X POST "$BASE_URL/api/games.php?action=update" \
  -H 'Content-Type: application/json' \
  -d "{\"id\":$NEW_ID,\"front_cover_image\":\"data:image/gif;base64,!!!not-base64!!!\"}")

BADLEN=$(test_mysql -N -e "SELECT LENGTH(front_cover_image) FROM games WHERE id = $NEW_ID")
[[ "$BADLEN" -lt 200 ]] \
  && { green "  PASS: a bad data URI did not overwrite with garbage"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: stored $BADLEN bytes: $BAD"; FAIL_COUNT=$((FAIL_COUNT+1)); }

test_mysql -e "DELETE FROM games WHERE id = $NEW_ID"

summarize
```

**Before writing this, check `tests/v2/lib.sh` for the actual helper names** (`login_as_test_user`, `test_mysql`, `$BASE_URL`, `$COOKIE_JAR`). Other suites in `tests/v2/` already authenticate against the v1 API — copy their idiom verbatim rather than inventing one. If the create endpoint uses a different action/shape, mirror an existing v1 test.

- [ ] **Step 2: Run it and confirm it fails**

```bash
cd ~/worktrees/cli-images
TEST_DB_USER=CammyBlack02 \
TEST_DB_PASS="$(grep -m1 '^password=' ~/.my.cnf | cut -d= -f2- | tr -d '"')" \
bash tests/v2/run-all.sh 2>&1 | grep -A12 'test_base64_rejected'
```
Expected: FAIL — the stored value is thousands of bytes and begins `data:`.

- [ ] **Step 3: Add storeDataUriImage**

In `api/games.php`, directly below `downloadExternalImage` (which ends around line 490), add:

```php
/**
 * Decode a data: URI into a real file and return its bare filename.
 *
 * Mirrors downloadExternalImage's contract exactly — bare filename on success,
 * false on failure — so the two localisation branches at the call sites stay
 * symmetrical.
 *
 * This exists because a cover column may hold a filename or a URL and never an
 * image. Storing the URI verbatim put ~113 MB of base64 into the games table by
 * 2026-08-04, which is most of the database, and it ships in full to the phone
 * on every delta sync because api/v2/sync/changes.php selects whole rows.
 *
 * @return string|false
 */
function storeDataUriImage($dataUri, $gameId = null, $type = 'front') {
    if (!is_string($dataUri) || strpos($dataUri, 'data:') !== 0) {
        return false;
    }

    $comma = strpos($dataUri, ',');
    if ($comma === false) {
        return false;
    }

    $header  = substr($dataUri, 5, $comma - 5);   // e.g. "image/png;base64"
    $payload = substr($dataUri, $comma + 1);

    if (stripos($header, 'base64') === false) {
        return false;
    }

    // Strict: reject anything with characters outside the base64 alphabet
    // rather than silently decoding a truncated payload into a corrupt file.
    $binary = base64_decode($payload, true);
    if ($binary === false || $binary === '') {
        return false;
    }

    // Magic-byte check, not the declared MIME: the header is client-supplied.
    $info = @getimagesizefromstring($binary);
    if ($info === false) {
        return false;
    }

    $extension = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ][$info[2]] ?? null;

    if ($extension === null) {
        return false;
    }

    $filename   = generateUniqueFilename('cover.' . $extension, COVERS_DIR);
    $targetPath = COVERS_DIR . $filename;

    if (file_put_contents($targetPath, $binary) === false) {
        return false;
    }

    require_once __DIR__ . '/../includes/thumbnail.php';
    gt_generate_thumbnail($targetPath, gt_thumbnail_path($targetPath), 512);

    return $filename;
}

/**
 * Normalise whatever the client sent for an image column into a stored value.
 *
 * One helper for both create and update, because they had drifted: update
 * localised http(s) URLs while create localised nothing, which is why 51 rows
 * hold a URL rather than a file.
 *
 * @return array{0: string|null, 1: string|null} [value to store, error or null]
 */
function normaliseImageValue($value, $gameId, $type) {
    if (!is_string($value) || $value === '') {
        return [$value, null];
    }

    if (strpos($value, 'data:') === 0) {
        $stored = storeDataUriImage($value, $gameId, $type);

        // Deliberately an error rather than a fallback: storing the URI is the
        // behaviour being removed, so failing loudly is the point.
        return $stored === false
            ? [null, 'Could not decode the supplied image data']
            : [$stored, null];
    }

    if (strpos($value, 'http://') === 0 || strpos($value, 'https://') === 0) {
        $downloaded = downloadExternalImage($value, $gameId, $type);

        // A URL that will not fetch is kept as a URL — that is pre-existing
        // behaviour and the column legitimately holds URLs.
        return [$downloaded === false ? $value : $downloaded, null];
    }

    return [$value, null];
}
```

- [ ] **Step 4: Use it in updateGame**

Replace the existing localisation block (around lines 593-609, the two
`downloadExternalImage` call sites) with:

```php
        foreach (['front_cover_image' => 'front', 'back_cover_image' => 'back'] as $column => $face) {
            if (!isset($data[$column])) {
                continue;
            }
            [$stored, $error] = normaliseImageValue($data[$column], $id, $face);
            if ($error !== null) {
                sendJsonResponse(['success' => false, 'message' => $error], 400);
            }
            $data[$column] = $stored;
        }
```

- [ ] **Step 5: Use it in createGame**

`createGame` currently inserts `$frontCover` / `$backCover` raw. Immediately
before the `INSERT INTO games` prepare (around line 393), add:

```php
    // create localised nothing before this: a data URI or an http URL supplied
    // at creation was stored verbatim.
    foreach ([['front', 'frontCover'], ['back', 'backCover']] as [$face, $var]) {
        [$stored, $error] = normaliseImageValue($$var, null, $face);
        if ($error !== null) {
            sendJsonResponse(['success' => false, 'message' => $error], 400);
        }
        $$var = $stored;
    }
```

If the local variables are not named `$frontCover` / `$backCover` at that point,
use whatever the INSERT actually binds — read the `execute([...])` array
directly rather than assuming.

- [ ] **Step 6: Run the harness and confirm it passes**

Expected: `test_base64_rejected.sh` green, every pre-existing suite still green.
Pay particular attention to any existing cover-image suite — this changes a
shared write path.

- [ ] **Step 7: Commit**

```bash
git add api/games.php tests/v2/test_base64_rejected.sh
git commit -m "fix(api): store images as files, never as data URIs

A cover column may hold a filename or a URL. api/games.php localised http(s)
URLs in updateGame and nothing at all in createGame, so a data: URI was stored
verbatim — ~113 MB of base64 across 81 rows by 2026-08-04, most of the
database, shipped in full to the phone on every delta sync.

An undecodable URI is now a 400 rather than a silent store, because falling
back would reinstate the behaviour being removed."
```

---

### Task 2: StorageMode

**Files:** Create `src/Images/StorageMode.php`, `tests/cli/test_gt_images.sh`

**Interfaces:**
- Produces: `StorageMode::of(string $value): string` returning `'data-uri' | 'url' | 'filename' | 'empty'`; `StorageMode::isFile(string $value): bool`

- [ ] **Step 1: Write the failing test**

Create `tests/cli/test_gt_images.sh`:

```bash
#!/usr/bin/env bash
# Image storage-mode classification and reconciliation.
source "$(dirname "$0")/../v2/lib.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

mode() {
  php -r 'require $argv[1]."/src/autoload.php"; echo GameTracker\Images\StorageMode::of($argv[2]);' \
    -- "$PROJECT_ROOT" "$1"
}

blue "Storage mode classification"

assert_eq "filename" "$(mode 'cover_123_abc.jpg')"                  "a bare filename"
assert_eq "url"      "$(mode 'https://cdn.example.com/a.jpg')"      "an https URL"
assert_eq "url"      "$(mode 'http://cdn.example.com/a.jpg')"       "an http URL"
assert_eq "empty"    "$(mode '')"                                   "an empty value"
assert_eq "data-uri" "$(mode 'data:image/gif;base64,R0lGODlhAQ==')" "a data URI"

# The exact input that broke the 2026-08-03 audit: base64 contains '/', so
# taking a basename turns an inline image into a fake filename like "9k=".
assert_eq "data-uri" "$(mode 'data:image/jpeg;base64,AAAA/BBBB//9k=')" \
  "a data URI containing slashes is not a filename"

summarize
```

- [ ] **Step 2: Run and confirm failure.** Class not found.

- [ ] **Step 3: Implement**

Create `src/Images/StorageMode.php`:

```php
<?php

namespace GameTracker\Images;

/**
 * What an image column is actually holding.
 *
 * The single place this decision is made. Every audit and every filesystem
 * check must branch on it first: only a filename lives on disk, and treating
 * the other kinds as paths is precisely the bug that produced the wrong
 * 2026-08-03 figures — base64 contains '/', so a basename of a data URI yields
 * a plausible-looking filename such as "9k=" that is then reported missing.
 */
final class StorageMode
{
    public const DATA_URI = 'data-uri';
    public const URL      = 'url';
    public const FILENAME = 'filename';
    public const EMPTY    = 'empty';

    public static function of(?string $value): string
    {
        $value = $value === null ? '' : trim($value);

        if ($value === '') {
            return self::EMPTY;
        }
        if (stripos($value, 'data:') === 0) {
            return self::DATA_URI;
        }
        if (stripos($value, 'http://') === 0 || stripos($value, 'https://') === 0) {
            return self::URL;
        }

        return self::FILENAME;
    }

    /** True only for values that correspond to a file under uploads/. */
    public static function isFile(?string $value): bool
    {
        return self::of($value) === self::FILENAME;
    }
}
```

- [ ] **Step 4: Run and confirm it passes.**

- [ ] **Step 5: Commit**

```bash
git add src/Images/StorageMode.php tests/cli/test_gt_images.sh
git commit -m "feat(cli): StorageMode, one place that classifies an image value

Base64 contains '/', so taking a basename of a data URI invents a filename
like '9k=' which is then reported as a missing file. That artefact produced
the wrong 2026-08-03 audit figures. Every disk check branches here first."
```

---

### Task 3: Reconciler

Pure logic — no filesystem, no database, so it is testable without fixtures.

**Files:** Create `src/Images/Reconciler.php`; modify `tests/cli/test_gt_images.sh`

**Interfaces:**
- Produces: `Reconciler::reconcile(array $referenced, array $onDisk, array $thumbs): array{orphans: list<string>, missing: list<string>, prunableThumbs: list<string>, keptThumbs: int}`
  - `$referenced` — filenames only, already filtered through `StorageMode::isFile`
  - `$onDisk` — source filenames, excluding thumbnails
  - `$thumbs` — thumbnail filenames (same basename as their source)

- [ ] **Step 1: Write the failing test**

Append to `tests/cli/test_gt_images.sh`:

```bash
blue "Reconciliation"

RESULT=$(php -r '
  require $argv[1]."/src/autoload.php";
  $r = GameTracker\Images\Reconciler::reconcile(
      ["a.jpg", "b.jpg", "gone.jpg"],        // referenced
      ["a.jpg", "b.jpg", "orphan.jpg"],      // on disk
      ["a.jpg", "orphan.jpg"]                // thumbs
  );
  echo implode(",", $r["orphans"]), "|",
       implode(",", $r["missing"]), "|",
       implode(",", $r["prunableThumbs"]), "|",
       $r["keptThumbs"];
' -- "$PROJECT_ROOT")

assert_eq "orphan.jpg|gone.jpg|orphan.jpg|1" "$RESULT" \
  "orphans, missing, and thumbs following their source"

blue "A thumbnail whose source is referenced is never prunable"

SAFE=$(php -r '
  require $argv[1]."/src/autoload.php";
  $r = GameTracker\Images\Reconciler::reconcile(["live.jpg"], ["live.jpg"], ["live.jpg"]);
  echo count($r["prunableThumbs"]), " ", $r["keptThumbs"];
' -- "$PROJECT_ROOT")

assert_eq "0 1" "$SAFE" "a live thumbnail is kept"
```

- [ ] **Step 2: Run and confirm failure.**

- [ ] **Step 3: Implement**

Create `src/Images/Reconciler.php`:

```php
<?php

namespace GameTracker\Images;

/**
 * Compares what the database references against what is on disk.
 *
 * Pure: no filesystem, no database. The caller gathers both sides, so this can
 * be tested exhaustively without fixture trees, and the expensive I/O happens
 * once at the edge rather than inside the logic.
 */
final class Reconciler
{
    /**
     * @param list<string> $referenced filenames only — caller must have filtered
     *                                 through StorageMode::isFile first
     * @param list<string> $onDisk     source files, excluding thumbnails
     * @param list<string> $thumbs     thumbnail files, named after their source
     *
     * @return array{orphans: list<string>, missing: list<string>, prunableThumbs: list<string>, keptThumbs: int}
     */
    public static function reconcile(array $referenced, array $onDisk, array $thumbs): array
    {
        $referencedSet = array_flip($referenced);

        $orphans = array_values(array_filter(
            $onDisk,
            static fn(string $file): bool => !isset($referencedSet[$file])
        ));

        $onDiskSet = array_flip($onDisk);
        $missing = array_values(array_filter(
            $referenced,
            static fn(string $file): bool => !isset($onDiskSet[$file])
        ));

        // A thumbnail is derived, never referenced by any row, so it cannot be
        // judged on its own. It follows its source: 1,089 of the 1,187 thumbs
        // in production have a referenced source, and a sweep that ignored this
        // would destroy every one of them.
        $prunableThumbs = [];
        $keptThumbs = 0;

        foreach ($thumbs as $thumb) {
            if (isset($referencedSet[$thumb])) {
                $keptThumbs++;
            } else {
                $prunableThumbs[] = $thumb;
            }
        }

        return [
            'orphans' => $orphans,
            'missing' => $missing,
            'prunableThumbs' => $prunableThumbs,
            'keptThumbs' => $keptThumbs,
        ];
    }
}
```

- [ ] **Step 4: Run and confirm it passes.**

- [ ] **Step 5: Commit**

```bash
git add src/Images/Reconciler.php tests/cli/test_gt_images.sh
git commit -m "feat(cli): pure Reconciler for referenced-vs-on-disk

Thumbnails are derived and referenced by nothing, so they follow their source.
1,089 of 1,187 production thumbs have a referenced source; a sweep that judged
them on their own would destroy all of them."
```

---

### Task 4: `gt images audit`

**Files:** Create `src/Images/ImageIndex.php`, `src/Cli/Commands/Images/AuditCommand.php`; modify `src/Cli/Application.php`; delete `scripts/audit-images.sh`

**Interfaces:**
- Produces:
  - `ImageIndex::referencedFor(PDO, ?int $userId): array{filenames: list<string>, byMode: array<string,int>}` — reads all six image columns, classifying each through `StorageMode`
  - `ImageIndex::onDisk(string $uploadsDir): array{sources: list<string>, thumbs: list<string>}`

- [ ] **Step 1: Write the failing test** — append to `tests/cli/test_gt_images.sh`:

```bash
blue "gt images audit"

GT="$PROJECT_ROOT/bin/gt"
GT_CODE=0; GT_JSON=""
run_gt_json() { set +e; GT_JSON=$("$GT" "$@" 2>/dev/null); GT_CODE=$?; set -e; }

run_gt_json images audit --user=gtfixture
assert_eq "0" "$GT_CODE" "images audit exits 0"
echo "$GT_JSON" | jq -e 'has("by_mode") and has("orphans") and has("missing") and has("thumbnails")' > /dev/null \
  && { green "  PASS: reports modes, orphans, missing and thumbnails"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: unexpected audit shape: $GT_JSON"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Audit must never write.
echo "$GT_JSON" | jq -e '.dry_run != false' > /dev/null \
  && { green "  PASS: audit is read-only"; PASS_COUNT=$((PASS_COUNT+1)); } \
  || { red "  FAIL: audit reported a write"; FAIL_COUNT=$((FAIL_COUNT+1)); }
```

- [ ] **Step 2: Run and confirm failure** — unknown command `images`.

- [ ] **Step 3: Implement `ImageIndex`**

Create `src/Images/ImageIndex.php`. It reads the six columns and classifies
every value through `StorageMode`, returning only filenames for disk comparison
plus per-mode counts for reporting. SELECT only — it lives in `src/Images/`, so
the read-only guard forbids write SQL here.

```php
<?php

namespace GameTracker\Images;

use PDO;

/**
 * Gathers both sides of the reconciliation: what rows reference, and what is on
 * disk. Read-only.
 */
final class ImageIndex
{
    /** table => column pairs holding an image reference */
    private const COLUMNS = [
        ['games', 'front_cover_image'],
        ['games', 'back_cover_image'],
        ['items', 'front_image'],
        ['items', 'back_image'],
        ['game_images', 'image_path'],
        ['item_images', 'image_path'],
    ];

    /**
     * @return array{filenames: list<string>, byMode: array<string, array<string,int>>}
     */
    public static function referencedFor(PDO $pdo, ?int $userId = null): array
    {
        $filenames = [];
        $byMode = [];

        foreach (self::COLUMNS as [$table, $column]) {
            $sql = "SELECT `{$column}` AS v FROM {$table} WHERE `{$column}` IS NOT NULL AND `{$column}` <> ''";
            $params = [];

            if ($userId !== null) {
                $sql .= ' AND `user_id` = ?';
                $params[] = $userId;
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $counts = [
                StorageMode::DATA_URI => 0,
                StorageMode::URL => 0,
                StorageMode::FILENAME => 0,
            ];

            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $value = (string)$row['v'];
                $mode = StorageMode::of($value);

                if (isset($counts[$mode])) {
                    $counts[$mode]++;
                }

                if ($mode === StorageMode::FILENAME) {
                    // basename only — and only ever applied to a value already
                    // known to be a filename, which is what makes it safe.
                    $filenames[] = basename($value);
                }
            }

            $byMode["{$table}.{$column}"] = $counts;
        }

        return ['filenames' => array_values(array_unique($filenames)), 'byMode' => $byMode];
    }

    /**
     * @return array{sources: list<string>, thumbs: list<string>}
     */
    public static function onDisk(string $uploadsDir): array
    {
        $sources = [];
        $thumbs = [];

        foreach (['covers', 'extras'] as $sub) {
            $dir = rtrim($uploadsDir, '/') . '/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }

            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_file($dir . '/' . $entry)) {
                    $sources[] = $entry;
                }
            }

            $thumbDir = $dir . '/thumbs';
            if (!is_dir($thumbDir)) {
                continue;
            }

            foreach (scandir($thumbDir) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                if (is_file($thumbDir . '/' . $entry)) {
                    $thumbs[] = $entry;
                }
            }
        }

        return [
            'sources' => array_values(array_unique($sources)),
            'thumbs' => array_values(array_unique($thumbs)),
        ];
    }
}
```

- [ ] **Step 4: Implement `AuditCommand`** at `src/Cli/Commands/Images/AuditCommand.php`, `NAME = 'images audit'`, `allowedOptions()` returning `[]`. It calls `ImageIndex::referencedFor`, `ImageIndex::onDisk`, then `Reconciler::reconcile`, and reports `by_mode`, `orphans`, `missing`, `thumbnails` (kept and prunable). Read-only: it must never set `dry_run` to false.

Register `'images audit'` in `src/Cli/Application.php`.

- [ ] **Step 5: Delete the shell audit**

```bash
git rm scripts/audit-images.sh
```

Two things computing the same numbers by different routes is how they come to
disagree. Update the reference to it in
`docs/superpowers/specs/2026-08-03-gt-cli-mutations-design.md` to name
`gt images audit`.

- [ ] **Step 6: Verify against production's known figures**

```bash
cd /var/www/gameTracker   # read-only command, safe here
~/worktrees/cli-images/bin/gt images audit --json
```
Expected, per the 2026-08-04 measurement: 99 orphans, 48 missing, 1,187
thumbnails of which 1,089 kept, and `games.front_cover_image` showing 42
data-uri / 51 url / 810 filename. **If these disagree, stop** — either the audit
is wrong or something changed, and both need explaining before pruning anything.

- [ ] **Step 7: Commit.**

---

### Task 5: Trash and `gt images prune`

**Files:** Create `src/Services/Write/Trash.php`, `src/Cli/Commands/Images/PruneCommand.php`; modify `src/Cli/Application.php`, `tests/cli/test_gt_images.sh`

**Interfaces:**
- Produces:
  - `Trash::dir(): string` — `~/.gt/trash`, `GT_TRASH_DIR` overrides (tests must set it)
  - `Trash::move(array $files, string $uploadsDir): array{id: string, moved: int, failed: int}`
  - `Trash::restore(string $id, string $uploadsDir): array{restored: int, skipped: int}`
  - `Trash::list(): list<array{id: string, files: int, at: string}>`

- [ ] **Step 1: Write the failing test.** Assertions that must exist:
  - prune without `--yes` previews and **moves nothing**
  - with `--yes`, orphan files leave `uploads/` and **appear under the trash dir** — assert the file exists in trash, not merely that it is gone
  - a thumbnail whose source is referenced is still present afterwards
  - `--restore=<id>` puts the files back
  - restoring twice is refused rather than silently re-moving

Tests set `GT_TRASH_DIR` and operate on a temporary uploads tree, never the real `uploads/`.

- [ ] **Step 2: Run and confirm failure.**

- [ ] **Step 3: Implement `Trash`.** Move semantics: `rename()` where possible, `copy()` + `unlink()` across filesystems. Preserve the `thumbs/` sub-path inside the trash directory so restore can put everything back where it came from. Never delete: `restore` moves back, and emptying the trash is a manual act with no CLI support, deliberately.

- [ ] **Step 4: Implement `PruneCommand`** — `NAME = 'images prune'`, options `['yes', 'restore']`. Previews by default; `--yes` applies; `--restore=<id>` reverses. Journalled so the operation appears in history.

- [ ] **Step 5: Run the harness.**

- [ ] **Step 6: Commit.**

---

### Task 6: `--broken-cover`

**Files:** Modify `src/Query/GamesFilters.php`, `src/Query/ItemsFilters.php`, the list command(s); `tests/cli/test_gt_images.sh`

- [ ] **Step 1: Write the failing test.** Must assert:
  - a row whose filename is absent from disk is matched
  - a row holding an `http` URL is **not** matched (51 game covers and 58 item images are URLs — treating them as broken is the original bug)
  - a row holding a `data:` URI is **not** matched
  - a row whose file exists is not matched

- [ ] **Step 2: Run and confirm failure.**

- [ ] **Step 3: Implement.** `--broken-cover` cannot compile to SQL — the filesystem is not something a `WHERE` clause can consult — so it is a post-fetch predicate. Two consequences to implement explicitly:
  - It **implies full paging**. Filtering after `LIMIT` would report "3 broken on this page" rather than the truth. Force `perPage` to unlimited when it is set, and say so in `--help`.
  - It branches on `StorageMode` before any `is_file()` call.

- [ ] **Step 4: Run and confirm it passes. Step 5: Commit.**

---

### Task 7: Convert the existing 81 rows, docs, verification

**This task writes ~113 MB to production. It is a runbook, not code, and it needs Cameron's explicit go-ahead at Step 2 — do not run `--execute` on his behalf without it.**

- [ ] **Step 1: Verify a backup artifact, not a log line**

```bash
ls -lh /var/backups/gameTracker/database_*.sql.gz | tail -2
LATEST=$(ls -t /var/backups/gameTracker/database_*.sql.gz | head -1)
zcat "$LATEST" | grep -c 'CREATE TABLE'          # expect 11
zcat "$LATEST" | tail -5 | grep -c 'Dump completed'   # expect 1
```
Backups on this project have reported success while producing 0-byte files for
their entire life. **Check the artifact.** If the latest dump is stale or
malformed, take a fresh one and verify it before continuing.

- [ ] **Step 2: Dry run, and show Cameron the plan**

```bash
cd /var/www/gameTracker && php scripts/migrate-base64-covers.php | tail -20
```
Expected: 81 cells across `games.front_cover_image` and `games.back_cover_image`.
**Stop here and report. Do not proceed without explicit approval.**

- [ ] **Step 3: Execute (only after approval)**

```bash
cd /var/www/gameTracker && php scripts/migrate-base64-covers.php --execute
```

- [ ] **Step 4: Verify the conversion**

```bash
./bin/gt images audit --json | jq '.by_mode'
```
Expected: every `data-uri` count is now 0.

```bash
mysql gameTracker -N -B -e "
SELECT CONCAT('remaining data URIs: ',
  (SELECT COUNT(*) FROM games WHERE front_cover_image LIKE 'data:%' OR back_cover_image LIKE 'data:%'))"
```
Expected: 0.

Then confirm the win is real — take a fresh dump and compare its size against
the 89 MB baseline. A dump that has not shrunk means the conversion did not do
what it claimed.

- [ ] **Step 5: Confirm the source is closed**

Re-run `tests/v2/test_base64_rejected.sh` against production's deployed code
path once Task 1 has shipped, or verify by hand that creating a game with a
data URI stores a filename. Conversion without this regresses.

- [ ] **Step 6: Bump `VERSION` to `0.5.0`, document in `CLAUDE.md`**

Cover: `gt images audit` / `gt images prune` / `--restore`; that prune moves to
trash and never unlinks; that trash is never auto-swept; that thumbnails follow
their source; that `--broken-cover` is a scan implying full paging; and that
image columns now hold a filename or a URL, never an image.

- [ ] **Step 7: Full harness + guard control**

```bash
bash tests/v2/run-all.sh; echo "exit=$?"
```
Then plant `INSERT INTO games ...` in `src/Images/StorageMode.php`, confirm the
read-only guard goes red, and restore the file.

- [ ] **Step 8: Commit and open the PR**, confirming CI with `gh pr checks` **and** the run's `conclusion`.

## Verification before opening the PR

- [ ] `bash tests/v2/run-all.sh` exits 0
- [ ] `gt images audit` reproduces the known production figures (99 / 48 / 1,187 with 1,089 kept)
- [ ] Prune moved files **into trash** — asserted by their presence there, not merely their absence from `uploads/`
- [ ] The read-only guard covers `src/Images/`
- [ ] No production image files were touched by any test
