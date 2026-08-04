# Level 2 — The two generations

## 3. v1 vs v2

Two parallel API generations with different auth, different response shapes and
different include graphs. This is the single biggest source of confusion in the
codebase, which is why it gets a diagram rather than a paragraph.

```mermaid
flowchart TB
    subgraph v1["v1 — api/*.php"]
        direction TB
        v1c["Browser, web frontend"]
        v1a["includes/auth.php<br/>session auth, user or admin"]
        v1r["{ success, message, ... }"]
        v1c --> v1a --> v1r
    end

    subgraph v2["v2 — api/v2/"]
        direction TB
        v2c["iPhone app"]
        v2a["api/v2/_auth.php<br/>bearer token auth"]
        v2r["ok: { data }<br/>error: { error, message }"]
        v2c --> v2a --> v2r
    end

    v1a -.->|"session cookie<br/>SameSite=Lax"| cookie[("PHP session")]
    v2a -.->|"bearer token:<br/>32 random bytes as 64 hex chars;<br/>the DB stores SHA-256, not bcrypt"| tok[("api_tokens")]

    v2 x--x|"NEVER include a v1 file"| v1
```

| | v1 | v2 |
|---|---|---|
| Lives in | `api/*.php` | `api/v2/` |
| Serves | the web frontend | the iOS app |
| Auth | session cookie | bearer token, *or* an active session |
| Success shape | `{ success: true, ... }` | `{ data: ... }` |
| Error shape | `{ success: false, message }` | `{ error: "slug", message }` |
| Mutations | must be POST — 405 otherwise | per-endpoint |

Things worth knowing that the boxes cannot show:

- **SHA-256 rather than bcrypt is deliberate**, not an oversight. A token is 32
  bytes of entropy already, so the hash only needs to be a cheap deterministic
  lookup key.
- **v2 never includes a v1 file.** An earlier design set `$_SESSION` and
  installed an output buffer to reshape v1 responses into v2 shape. That is gone;
  do not reintroduce it.
- **POST-only mutation plus `SameSite=Lax` is what currently stands in for full
  CSRF enforcement** on v1. `includes/csrf.php` exists and is waiting on the
  frontend rewrite.
- The browser is deliberately never issued a bearer token: an HttpOnly session
  cookie cannot be read by injected JS, and any token the frontend could attach
  could also be stolen.

**Goes stale when:** auth or response shape changes on either generation, or the
v1/v2 isolation rule is relaxed.

## 4. Data model

Twelve tables. The delete rules on the edges *are* the behaviour, so they are
drawn rather than described.

```mermaid
erDiagram
    users ||--o{ games : "CASCADE"
    users ||--o{ items : "CASCADE"
    users ||--o{ settings : "CASCADE"
    users ||--o{ api_tokens : "CASCADE"
    users ||--o{ game_completions : "CASCADE"
    users ||--o{ game_images : "CASCADE"
    users ||--o{ item_images : "CASCADE"
    games ||--o{ game_images : "CASCADE"
    items ||--o{ item_images : "CASCADE"
    games ||--o{ game_completions : "SET NULL -- the odd one out"
```

Standalone tables with no foreign keys: `deletions` (tombstones, not domain
data), `schema_migrations` (the migration ledger), `security_logs`,
`rate_limits`.

**`game_completions.game_id` is the only `ON DELETE SET NULL`** in the schema.
Every other child cascades. That single asymmetry is the sync bug the writes half
of sub-project #6b closes: deleting a game silently orphans its completion rows
instead of removing them, and nothing tells the phone. `gt`'s delete already
journals and relinks them; the web path does not yet.

Row counts **as of 2026-08-04** — a dated snapshot, not a standing fact:

| Table | Rows |
|---|---|
| `games` | 1,042 |
| `items` | 108 |
| `game_completions` | 51 |
| `api_tokens` | 17 |
| `deletions` | 13 |
| `game_images` | **0** |
| `item_images` | **0** |

Both image tables being empty is worth noticing: the extra-images feature has
produced no rows at all. The `uploads/` directory did not survive the December
2025 server rebuild, so the innocent explanation is that those rows went with it
and nothing has added any since.

**Goes stale when:** a migration adds or removes a table, or changes a delete
rule.
