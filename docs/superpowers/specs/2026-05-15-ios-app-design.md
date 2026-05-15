# gameTracker iOS App — Design

**Date:** 2026-05-15
**Status:** Design approved, awaiting implementation plan
**Author:** Cameron (with Claude)

## Overview

Build a native iOS app (`gameTracker-iOS`) that complements the existing PHP/MySQL web app. The phone holds a full offline copy of the user's game collection, syncs bidirectionally with the web app's server when online, and surfaces a "Currently Playing" home-screen widget.

The existing web app at gameTracker continues to operate unchanged. The iOS app is single-user (the owner); the web app's multi-user features remain intact for use by others.

## Goals

- Use the collection on iPhone without depending on the server being reachable
- Two-way sync between phone and server, with explicit conflict resolution when both sides edit the same record between syncs
- Native iOS feel (gestures, navigation, system fonts) using the web app's dark colour palette
- Distribute via free Apple ID sideloading (Sideloadly)

## Non-goals (out of scope)

- App Store distribution
- iPad layout (iPhone-only; iPad can render the iPhone version compatibly but is not optimised)
- Steam import and GameEye CSV import (continue to use the web app for these)
- Admin dashboard, user management, registration on the phone (web-only)
- Replacing the web app

## Key Decisions (from brainstorming Q&A)

| Decision | Choice |
|---|---|
| App / server relationship | **Hybrid** — full local DB on phone, two-way sync with server |
| Conflict resolution | **Conflict prompt** — show both versions, user picks per record (with field-level merge option) |
| Single- vs multi-user on phone | **Single-user** (owner only); web app retains multi-user |
| Features included | All web features **except** Steam import and GameEye CSV import |
| New iOS-only features | **Currently Playing widget** (medium size) |
| Distribution | **Free Apple ID + Sideloadly** (weekly re-sign) |
| Server reachability | **DuckDNS public hostname** — phone can sync from anywhere with internet |
| Image sync strategy | **Cover thumbnails synced locally; full-res covers and extras download on demand** |
| Visual style | **Hybrid** — native iOS interaction patterns + web app's dark colour palette |
| Build framework | **Native Swift / SwiftUI** (rejected: React Native, Capacitor) |

---

## Section 1: High-level architecture

```
┌──────────────────┐       HTTPS + JSON       ┌──────────────────┐
│   iPhone app     │  ◄─────────────────────► │  Linux server    │
│  (SwiftUI)       │     (DuckDNS hostname)   │  (PHP + MySQL)   │
│                  │                          │                  │
│ • Local SQLite   │                          │ • Existing site  │
│   (SwiftData)    │                          │   unchanged      │
│ • Cached images  │                          │ • New /api/v2/   │
│ • Widget         │                          │   endpoints      │
│ • Sync engine    │                          │ • New MySQL      │
│                  │                          │   sync columns   │
└──────────────────┘                          └──────────────────┘
```

- Phone has its own SQLite database via Apple's **SwiftData** framework (modern Core Data successor)
- All UI reads from the local DB → instant rendering, full offline support
- A **sync engine** reconciles phone ⇄ server when network is reachable
- New `/api/v2/` endpoints on the server use **Bearer token authentication** (rather than the cookie sessions the web UI uses)
- The web app's UI, MySQL schema (with additive changes only), and existing endpoints remain functional

## Section 2: Data model & local storage

### Local SQLite (via SwiftData)

Each synced table has three sync columns added beyond the columns that mirror the server schema:

| Column | Purpose |
|---|---|
| `server_id` | Server's MySQL primary key. `NULL` until first sync after local creation. |
| `updated_at` | Timestamp of last local change. Drives sync ordering. |
| `sync_state` | One of: `synced`, `local_modified`, `local_new`, `local_deleted`, `conflict` |

### Tables that sync

- `games` — primary entity
- `consoles` — items (one of the two item types)
- `accessories` — items (the other)
- `completions` — per-game completion log entries
- `extras` — additional photos per game (metadata only on phone; full-res on demand)
- `image_cache` — phone-only table tracking which cover thumbnails are stored locally (server URL, local file path, last-fetched timestamp)

### Tables that do NOT sync (server-only)

- `users`, `sessions`, `api_tokens` (the phone only knows its own user via the token)
- `security_logs`, rate-limiting tables
- Other users' settings and profiles

### Image storage on phone

| Image type | Where it lives | When fetched |
|---|---|---|
| Cover thumbnails (~200 KB) | `Documents/covers/` | During sync, for every game |
| Full-resolution covers | `Caches/covers-full/` | On demand when user opens a game detail |
| Extra photo thumbnails | `Documents/extras/` | During sync, for every game |
| Extra photos full-res | `Caches/extras-full/` | On demand when user opens the photo viewer |

If the total thumbnail footprint grows uncomfortable for large collections, we can switch extra-photo thumbnails to a rolling LRU (only the last N opened games) — but the simpler "sync everything" default ships first.

`Documents/` is backed up to iCloud; `Caches/` is not, and iOS may evict it when storage is tight (this is fine — files re-download when needed).

### Why SwiftData over raw SQLite

- Type-safe Swift model classes (similar role to Java JPA entities)
- Automatic schema migration when model fields change
- Direct SwiftUI integration: views observing model objects auto-refresh on data change

## Section 3: Sync engine & conflict resolution

### When sync runs

- App launch (if online)
- Pull-down-to-refresh on any list view
- Debounced (~5 sec) after any local change, batching flurries of edits
- App returning to foreground after being backgrounded
- User taps "Sync now" in Settings

### The delta-sync protocol

Both sides exchange only what changed since the last successful sync.

```
1. PHONE → SERVER
   GET /api/v2/sync/changes?since=<last_sync_iso8601>

2. SERVER → PHONE (response)
   {
     "games":       [ ...rows changed since last sync... ],
     "consoles":    [ ... ],
     "accessories": [ ... ],
     "completions": [ ... ],
     "extras":      [ ... ],
     "deletions":   [ { "table": "games", "server_id": 42 }, ... ],
     "server_now":  "2026-05-15T14:32:00Z"
   }

3. PHONE → SERVER
   POST /api/v2/sync/push
   {
     "games":       [ ...rows where sync_state != 'synced'... ],
     "consoles":    [ ... ],
     ...
   }

4. SERVER → PHONE (response per pushed row)
   - "accepted":  { server_id, updated_at }
   - "conflict":  { server_version }  // server's current row, phone decides
```

### Conflict detection rule

A pushed row is in conflict iff **both** of:

- Phone's `sync_state = local_modified`
- Server's `updated_at` for that row is newer than the phone's `last_synced_at` for that row

In plain English: "I changed this locally, but someone (you, on the web) also changed it on the server since I last synced."

### Conflict resolution UI

After sync, conflicted rows are marked `sync_state = conflict` and surfaced via:

1. A persistent red banner at the top of the Library tab: `"N sync conflicts — tap to resolve"`
2. The conflict screen, shown one game at a time:

```
┌─ Conflict: Halo: Reach ────────────┐
│                                    │
│  📱 Your phone version             │
│  Status: Completed                 │
│  Notes: "Speedrun attempt 1"       │
│  Edited: 2 hours ago               │
│  [ Keep this version ]             │
│                                    │
│  ☁️  Server version                │
│  Status: Playing                   │
│  Notes: "Speedrun attempt 1 - WIP" │
│  Edited: 30 minutes ago            │
│  [ Keep this version ]             │
│                                    │
│  [ Merge fields manually ]         │
└────────────────────────────────────┘
```

"Merge fields manually" expands to a per-field picker for the fields that differ.

### Deletion semantics

- Phone deletes a row, server hasn't changed it → deletion propagates to server on next sync
- Phone edits a row, server deletes it → server deletion wins (row removed from phone)
- Phone deletes a row, server edited it → server edit wins (deletion is reverted on phone — assumption is that deleting something the user also edited elsewhere is almost always a mistake)
- Both delete → no-op

### Locally-created rows

Rows with `server_id = NULL` (created on phone offline) cannot conflict — server doesn't know about them. They are simply inserted server-side and the new MySQL ID is stamped back onto the phone row.

## Section 4: Server-side changes

The existing PHP site is left intact. All additions live alongside.

### New endpoints (under `/api/v2/`)

| Endpoint | Purpose |
|---|---|
| `POST /api/v2/auth/token` | Exchange username + password for a long-lived Bearer token. Phone stores in iOS Keychain. |
| `POST /api/v2/auth/revoke` | Phone tells server to invalidate a token (logout). |
| `GET  /api/v2/sync/changes?since=<ts>` | Returns rows changed for this user since timestamp. |
| `POST /api/v2/sync/push` | Phone uploads local changes; server returns accept/conflict per row. |
| `GET  /api/v2/images/cover/<id>?size=thumb\|full` | Serves cover image. |
| `GET  /api/v2/images/extra/<id>?size=thumb\|full` | Serves extra photo. |
| `POST /api/v2/games/<id>/cover` | Upload a new cover (multipart). |
| `GET  /api/v2/pricecharting?title=X&platform=Y` | Pass-through to existing PriceCharting logic. |
| `GET  /api/v2/metacritic?title=X&platform=Y` | Pass-through to existing Metacritic logic. |
| `GET  /api/v2/external-image?url=X` | Phone passes a Google image URL; server downloads & saves (reuses `download-external-image.php`), returns local cover ID. |

All endpoints (except `auth/token`) require `Authorization: Bearer <token>` header.

### New MySQL schema additions

- New table `api_tokens` (id, user_id, hashed_token, created_at, last_used_at, device_name nullable)
- New table `deletions` (id, user_id, table_name, server_id, deleted_at) — tombstones so phone learns about web-side deletions
- New column `updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` added to: `games`, `consoles`, `accessories`, `completions`, `extras` — auto-maintained, so web-app edits also bump it correctly
- New folder `uploads/covers/thumbs/` and `uploads/extras/thumbs/` for generated thumbnails

### Thumbnail generation

- One-off PHP migration script: walks every existing cover and extra image, generates a thumbnail (~200 KB JPEG, longest edge ~512 px) into the `thumbs/` folders
- Hook into existing upload flow (`api/upload.php`, `api/download-external-image.php`): whenever a new image is saved, also generate its thumbnail

### Estimated server work

Roughly 800–1200 lines of PHP across ~12 new files in `api/v2/`, plus 2–3 MySQL migration scripts and the one-off thumbnail-backfill script.

## Section 5: Screens & navigation

The iOS app uses a 5-tab bottom tab bar.

```
┌─────────────────────────────────────────┐
│                                         │
│         (current screen content)        │
│                                         │
├─────────────────────────────────────────┤
│  📚      🎮      🎲      📊      ⚙️    │
│ Library  Items   Spin   Stats  Settings │
└─────────────────────────────────────────┘
```

### Tab 1: Library

- Top bar: search field, filter button, view-mode toggle (grid / list / coverflow)
- Grid view: 2-or-3-column cover grid, completion-status badge overlay
- List view: thumbnail + title + platform + status, one per row
- Coverflow: center cover large, neighbours fanning out (uses iOS smooth scrolling)
- Pull-down → sync & refresh
- "+" button (top right) → Add Game flow
- Tap a game → Game Detail screen
- Long-press a game → quick-actions menu (mark playing / completed / delete)
- Red conflict banner at top when sync conflicts exist

#### Add Game flow

1. Title + platform (required)
2. Paste Google image URL OR "Skip image for now"
3. Optional: tap "Fetch metadata" → calls PriceCharting + Metacritic via `/api/v2/`
4. Save (offline-capable; queued for sync if offline)

#### Game Detail screen

- Full metadata (all fields from current `game-detail.php`)
- Completion log
- Extra photos (thumbnails; tap → full-res viewer)
- Edit button
- Price & Metacritic (refreshable)

### Tab 2: Items

- Top sub-segmented control: **Consoles | Accessories**
- Same grid/list pattern as Library
- Tap → item detail screen with all fields from `item-detail.php`

### Tab 3: Spin

- Filters (platform, status, etc., matching current spin-wheel)
- Large "Spin" button
- Animated wheel picks a game
- Result shows cover + "Play this one" → opens game detail

### Tab 4: Stats

- Scrollable list of stat cards: total games, completion rate, time-to-complete histogram, breakdown by platform / genre / year acquired
- Matches what `stats.php` currently shows

### Tab 5: Settings

- **Sync**: last sync time, "Sync now", conflict count → resolution flow
- **Account**: logged-in username, "Sign out" (revokes token, wipes local DB)
- **Storage**: cached image space, "Clear cache" button
- **Appearance**: light / dark / system (default: system)
- **About**: app version, link to web server

### Currently Playing widget (separate target)

Medium-size home-screen widget:

```
┌────────────────────────────────┐
│  Currently Playing             │
│  ┌──────┐                      │
│  │ cover│  Halo: Reach         │
│  │      │  Xbox 360            │
│  │      │  Started 5 days ago  │
│  └──────┘                      │
└────────────────────────────────┘
```

- If multiple games are marked Playing, the widget rotates between them (via WidgetKit's `TimelineProvider`)
- Reads directly from the shared SwiftData container (no network)
- Tap → opens that game's detail page via deep link

## Section 6: Build, distribution & development workflow

### Project layout

```
~/Desktop/Personal-Projects/
├── gameTracker/              ← existing web app (unchanged repo)
│   └── api/v2/               ← new endpoints added here
└── gameTracker-iOS/          ← new Xcode project (new git repo)
    ├── GameTracker.xcodeproj
    ├── GameTracker/          ← main app target
    │   ├── Models/           ← SwiftData model classes
    │   ├── Views/            ← SwiftUI screens (one file per screen)
    │   ├── Sync/             ← sync engine
    │   ├── Networking/       ← API client
    │   └── Assets.xcassets   ← app icon, colour palette
    └── GameTrackerWidget/    ← widget target (separate from main app)
```

### Tooling & versions

- **Xcode** (free, Mac App Store, ~15 GB) — latest stable version on the work Mac
- **iOS deployment target: iOS 17.0** — required for SwiftData; iPhone running iOS 17 or newer needed on owner's device
- **Free Apple ID** (signed into Xcode Preferences → Accounts)
- **Sideloadly** (sideloadly.io) — installed on the Intel personal Mac only
- **iPhone + USB cable** — for first install and weekly re-sign

### Sideload re-sign cadence

Every 7 days the free-Apple-ID signing certificate expires. To refresh:

1. Newer (work) Mac: re-builds `.ipa` from current source
2. Transfer `.ipa` to Intel Mac (AirDrop)
3. Intel Mac: open Sideloadly, drag `.ipa`, plug in iPhone, click install
4. ~30 seconds → app good for another 7 days

A `make ipa` target in the iOS repo wraps the Xcode CLI invocation so this is one command.

### Two-Mac development workflow

```
┌─────────────────────────────────┐         ┌─────────────────────────────┐
│  Work Mac (newer)               │         │  Intel Mac (personal)       │
│  • Claude Code                  │         │  • Sideloadly only          │
│  • Xcode + iPhone Simulator     │ AirDrop │  • Receives .ipa            │
│  • All source editing & builds  │ .ipa    │  • Installs to iPhone       │
│  • Git push                     │ ──────► │                             │
└─────────────────────────────────┘         └─────────────────────────────┘
         │                                              ▲
         │ git push                                     │ git pull (optional)
         ▼                                              │
   ┌────────────────────┐                              │
   │ GitHub: gameTracker-iOS  ───────────────────────┘
   └────────────────────┘
```

- Day-to-day testing: iPhone Simulator on the work Mac (instant, no signing, no cable)
- Real-phone testing: build `.ipa` → AirDrop → Sideloadly → iPhone
- Source synced via GitHub (new repo for the iOS app)

### Server deployment

Same workflow currently used for `gameTracker` web app — `git pull` on Linux box (or rsync, whichever is current). The web app's existing deployment is unchanged.

### Owner's role during build

| Phase | Owner's involvement |
|---|---|
| Plan-writing (next) | Review the implementation plan; flag missing features or wrong ordering |
| Implementation | Answer design forks ("badge top-left or top-right?"); run commands when given |
| Testing | Use the app in Simulator + sideloaded on phone; report bugs, confusion, missing pieces |
| Server deploy | `git pull` (or equivalent) on Linux box when new endpoints ship |
| Sideloading | Drag `.ipa` into Sideloadly, click install, weekly |

The owner is **not** writing Swift code. Claude writes all code; the owner steers, tests, and decides.

### Build order (preview — to be detailed in implementation plan)

1. Server: new `/api/v2/` auth, sync endpoints, MySQL migrations, thumbnail backfill
2. iOS: Xcode project skeleton + SwiftData models + API client + Bearer-token auth
3. iOS: Sync engine + conflict resolution UI
4. iOS: Library tab (biggest surface area) + game detail + add/edit
5. iOS: Items, Spin, Stats, Settings tabs
6. iOS: Currently Playing widget
7. Sideload to phone, live with it, iterate on real-use friction

Each step is independently testable and produces a usable artifact.

---

## Open questions to revisit during implementation

- Exact thumbnail resolution / quality (depends on what looks good in grid view at iPhone display densities)
- Whether the conflict "merge fields" UI is needed in v1 or only "keep phone / keep server" (simplification possible)
- Whether the cover-flow view is worth the implementation cost on phone screens (could be cut if grid + list are enough)

These don't block the design and will be decided when we get to the relevant screens.
