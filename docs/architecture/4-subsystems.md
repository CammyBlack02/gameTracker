# Level 4 — The three subsystems that don't fit in your head

These are the parts worth re-reading rather than re-deriving.

## 7. Write path: journal, tombstones, undo

```mermaid
flowchart TB
    cmd["gt games set / create / delete"] --> guard{"selector given?"}
    guard -->|"no selector"| refuse["refused —<br/>pass --all to mean every row"]
    guard -->|"yes"| dry{"--yes given?"}
    dry -->|"no"| preview["dry run: writes nothing"]
    dry -->|"yes"| apply["src/Services/Write<br/>the only place write SQL lives"]

    apply --> rows[("rows")]
    apply --> journal[("~/.gt/journal<br/>one entry per applied write")]
    apply -->|"on delete"| tomb[("deletions<br/>tombstones")]

    journal --> undo["gt undo"]
    undo --> check{"row's updated_at<br/>still what the journal recorded?"}
    check -->|"no — something else touched it"| stop["refuses, unless --force"]
    check -->|"yes"| restore["restore"]

    restore --> r1["row back under its ORIGINAL id"]
    restore --> r2["child rows re-inserted"]
    restore --> r3["completions relinked"]
    restore --> r4["tombstones cleared"]
    restore --> r5["but a FRESH updated_at"]
```

**Why a restore takes a fresh `updated_at`, not the original.**
`api/v2/sync/changes.php` only returns rows newer than the client's cursor. A
restored row carrying its original timestamp would sit below the phone's cursor
forever: present on the server, permanently missing on the phone. The fresh
timestamp is what makes the restore visible to sync.

**Delete never unlinks image files.** Several games share one image path, so
removing the file would break a surviving game's cover. Leaving it is also what
makes delete reversible — a `mysqldump` restores rows, not files. Orphaned files
accumulate and `gt images prune` deals with them.

**The confinement is enforced in three layers** by
`tests/cli/test_readonly_guard.sh`, because a guard is only as good as its
non-vacuity: no write SQL outside `src/Services/Write`, that directory must
actually *contain* some, and the matching pattern is itself probed against known
write statements so it cannot silently stop matching.

**Goes stale when:** the journal format, tombstone handling, or undo guards
change.

## 8. iOS delta sync

```mermaid
sequenceDiagram
    participant P as iPhone
    participant C as "api/v2/sync/changes.php"
    participant U as "api/v2/sync/push.php"
    participant DB as MySQL

    Note over P: pull first
    P->>C: GET ?since=<ISO 8601 cursor>
    C->>DB: rows WHERE updated_at >= since
    DB-->>C: games, items, game_completions,<br/>game_images, item_images
    C->>DB: tombstones since cursor
    DB-->>C: deletions
    C-->>P: one streamed JSON body,<br/>ending with server_now

    Note over P,U: then push
    P->>U: new[] and updated[] with each row's base updated_at
    U->>DB: current updated_at for this row
    alt server is newer than the phone's base
        U-->>P: conflict + full server_version
    else unchanged since the phone last read it
        U->>DB: apply the write
        U-->>P: accepted + new updated_at
    end
```

**The cursor comparison is `>=`, not `>`.** MySQL's `updated_at` has second
precision, so a strict `>` drops any row whose timestamp rounds down to the
cursor value. The overlap that `>=` creates is harmless because the client looks
rows up by `server_id` before inserting or updating — it recognises a row it
already has instead of duplicating it.

Conflict detection is the phone sending back the server's `updated_at` from the
last time it successfully read the row. If the server's current value is newer,
something else edited it in between, and the server returns its full version
rather than choosing a winner.

The response ends with `server_now`, and that value becomes the phone's cursor for
the next pull. It is emitted with whole-second precision, which is the other half
of why the comparison is `>=`: the cursor the client sends back is itself rounded.

**Goes stale when:** the cursor comparison, the set of streamed tables, or the
conflict rule changes.

## 9. Image storage pipeline

An image column holds a **reference**, never an image.

```mermaid
flowchart TB
    col["an image column"] --> mode{"src/Images/StorageMode"}
    mode -->|"filename"| f["a file in uploads/"]
    mode -->|"url"| u["a remote URL"]
    mode -->|"data-uri"| d["inline base64 — converted<br/>to a file on write"]
    mode -->|"empty"| e["nothing"]

    f --> thumb["thumbnails:<br/>derived, referenced by nothing,<br/>so they follow their source"]

    subgraph audit["gt images audit / prune"]
        idx["src/Images/ImageIndex<br/>all the I/O, at the edge"]
        rec["src/Images/Reconciler<br/>pure: no disk, no database"]
        idx --> rec
    end

    f --> idx
    rec --> orph["orphans:<br/>on disk, referenced by nothing"]
    rec --> broken["broken:<br/>referenced, absent from disk"]

    orph --> prune["gt images prune"]
    prune --> trash[("~/.gt/trash/<id>/<br/>never unlinked; --restore brings it back")]
```

- **`data-uri` is converted to a file on write**, on both create and update,
  regardless of `auto_download`. Before that fix 81 rows held roughly 113 MB of
  base64 — most of the database — and every byte shipped to the phone on each
  delta sync.
- **`prune` never unlinks.** Files move to trash and `--restore` brings them
  back. There is no retention policy and no `empty` command, deliberately:
  sweeping the safety net on a timer defeats it.
- **`ImageIndex` does the I/O; `Reconciler` is pure.** The expensive filesystem
  and database work happens once at the edge, which lets the comparison logic be
  tested exhaustively without building fixture trees.
- **`audit` and `prune` derive the uploads directory from the same
  `includes/config.php` that provides `$pdo`**, so disk and database always
  describe one install. If most referenced files look absent, `audit` warns and
  `prune` refuses — that means the wrong directory far more often than a vanished
  collection. This is also why `gt doctor` must be run from the production
  checkout.
- Thumbnails are derived and referenced by nothing, so a naive "delete
  unreferenced files" sweep would destroy nearly all of them.

**Goes stale when:** a storage mode is added, or the trash and prune policy
changes.
