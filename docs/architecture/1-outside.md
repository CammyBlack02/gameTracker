# Level 1 — The system from outside

## 1. System context

One intermittently-powered laptop serves three different consumers, and reaches
four external services through a single guarded exit.

```mermaid
flowchart LR
    browser["Browser<br/>web app"]
    phone["iPhone<br/>SwiftUI"]
    cli["gt CLI<br/>runs on the box"]

    subgraph box["The box — one often-off laptop"]
        nginx["nginx<br/>TLS + static files"]
        fpm["php8.3-fpm"]
        db[("MySQL 8.0")]
        disk[("uploads/<br/>image files")]
    end

    guard{{"includes/http-fetch.php<br/>rejects private, loopback,<br/>link-local and reserved IPs;<br/>revalidates every redirect hop"}}

    tgdb["TheGamesDB"]
    steam["Steam Web API"]
    pricing["PriceCharting"]
    critic["Metacritic"]

    browser -->|"HTTPS<br/>session cookie"| nginx
    phone -->|"HTTPS<br/>bearer token"| nginx
    nginx --> fpm
    fpm --> db
    fpm --> disk
    cli -->|"in-process PDO<br/>never over HTTP"| db
    cli --> disk

    fpm --> guard
    guard --> tgdb
    guard --> steam
    guard --> pricing
    guard --> critic

    ddns["DuckDNS updater<br/>cron, every 5 min"] -->|"tracks the rotating WAN IP"| dns[("public DNS record")]
    dns -.-> nginx
```

Three things this diagram is trying to tell you:

- **The CLI is not a client.** `gt` talks to MySQL in-process through the same
  `includes/config.php` that gives the web app its `$pdo`. It never goes over
  HTTP, so it needs no auth token and is unaffected by nginx.
- **There is exactly one exit to the internet.** Every external fetch goes
  through `includes/http-fetch.php`. Calling `file_get_contents` or curl on a
  user-supplied URL anywhere else reopens the SSRF hole that
  `tests/v2/test_ssrf.sh` and `tests/v2/test_v2_cover_ssrf.sh` guard.
- **If the site resolves on the LAN but not by name, suspect DNS first.** The
  WAN IP rotates and a cron job chases it.

**Goes stale when:** a new external service is called, the box's stack changes,
or the SSRF guard stops being the single exit.

## 2. User journey

One game, from typing it in to it appearing on the phone. The least detailed
diagram here on purpose — it exists to make the other eight legible.

```mermaid
sequenceDiagram
    actor You
    participant Web as "api/games.php (v1)"
    participant DB as MySQL
    participant Net as "cover source"
    participant Sync as "api/v2/sync (v2)"
    participant Phone as iPhone

    You->>Web: POST action=create
    Web->>Web: authenticate — session cookie
    Web->>DB: INSERT games, updated_at = now
    DB-->>Web: new id
    Web-->>You: success

    Note over Web,Net: the cover is fetched AFTER the row commits,<br/>so network latency never holds row locks
    Web->>Net: fetch cover through http-fetch.php
    Net-->>Web: image bytes
    Web->>DB: UPDATE front_cover_image
    Note over Web,Net: a failed download is non-fatal — the row<br/>keeps a NULL cover and the failure is counted

    Phone->>Sync: GET changes since <cursor>
    Sync->>DB: rows with updated_at >= cursor
    DB-->>Sync: the new row
    Sync-->>Phone: games, items, completions, tombstones
```

**Goes stale when:** the create path changes, or covers stop being fetched after
commit.
