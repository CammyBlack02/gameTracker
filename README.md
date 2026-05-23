# Game Tracker

A self-hosted personal **video-game collection tracker**, available as both a multi-user web app and a native iPhone companion. Track what you own, where you played it, what it cost, how long it took to beat — and browse your shelves as 3D game cases in your pocket.

> **v2.0 ships the native iOS app.** Everything in the web app is reachable from your phone, with extras: a 3D CoverFlow browse mode, four themed appearances (Matrix, Retro Mac, Game Boy, CRT Amber), and a Space-Invaders-style mini-game where the invaders are your own game covers.

---

## v2.0 highlights

- **Native iOS app** — SwiftUI, SwiftData, syncs with your existing self-hosted backend.
- **Library, Items, Completions, and Stats tabs** — full parity with the web app's read/write surface, plus per-platform filters, sort options, and pull-to-refresh sync.
- **3D CoverFlow view mode** — every game rendered as a real `SCNBox` (SceneKit) with the cover on the front, back cover on the back, and a flip button to spin the focused case. Three media shapes (DVD case / cart / CD jewel) auto-detected from the platform string.
- **Four rich themes** — Matrix (with code-rain flourish), Retro Mac (platinum bevel), Game Boy (8-bit dithering on covers + Press Start 2P font), CRT Amber (scanline overlay).
- **Library Invaders mini-game** — endless Space-Invaders with your own game covers as invaders. Drag-to-aim, auto-fire, wave-based difficulty ramp, local best score, CC0 retro SFX.
- **Game and cover photo capture** — front + back covers, plus extra in-the-wild photos of items and cases. Uploaded over HTTPS to your backend.
- **Settings: theme picker, image cache size, sync status, sign-out.**
- **Image proxy** — server-side fetch for cover URLs avoids the iOS sandbox blocking arbitrary domains.

---

## What it does

### iOS app (new in v2.0)

- **Library** with list, grid, and CoverFlow view modes; search; multi-platform filter; sort by title / date added / platform / rating; tap to detail; swipe to delete; pull to sync.
- **Game detail / edit** with title, platform, genre, series, special-edition, condition, review, star rating, Metacritic rating, played flag, price paid + PriceCharting current price, physical/digital toggle, digital store, front + back cover images, release date, plus a wall of extra photos.
- **Items tab** for accessories, consoles, controllers, peripherals — same metadata + photo system as games.
- **Completions tab** to record each time you finish a game (date, length, notes), with a per-game completion history.
- **Stats tab** showing total games, totals by platform, completed count, total spent, average rating, and a top-rated list.
- **Settings tab** — appearance mode (system / light / dark / matrix / retro-mac / game-boy / crt-amber), image cache size, manual sync, sign-out.
- **Library Invaders** mini-game (launched from the Library toolbar) using your game covers as invaders.
- **Offline-first** — SwiftData local store + push/pull sync against the v2 server API.

### Web app (since v1)

- Multi-user game-collection management with admin dashboard.
- Grid / list / coverflow views.
- Filter by platform, genre, condition, completion status; search.
- Cover image management (local + external URL).
- Spin Wheel random-game picker with filters.
- Completion tracking and statistics.
- GameEye CSV import.
- HTTPS, rate limiting, CSRF + XSS protection, secure file uploads, fail2ban, session hardening.

---

## Architecture

```
+----------------+        HTTPS + JSON         +-----------------------+
|  iOS app       |  <-------------------->     |  PHP / nginx / MySQL  |
|  (SwiftUI)     |     /api/v2/* endpoints     |  (self-hosted)        |
+----------------+                             +-----------------------+
                                                          ^
                                                          |
                                                          |  HTTPS
                                                          |
                                                  +---------------+
                                                  |  Web app      |
                                                  |  (PHP + JS)   |
                                                  +---------------+
```

- **iOS client**: Swift 5.10+, SwiftUI, SwiftData, SceneKit (CoverFlow), SpriteKit (Invaders). Xcode 16+. Min iOS 18.
- **Server**: PHP 8.3 + nginx + MySQL 8, fronted by Let's Encrypt TLS.
- **Sync**: pull-based delta sync over a single `/api/v2/sync` endpoint with conflict resolution, plus per-resource CRUD endpoints.
- **Images**: cover thumbs cached locally on iOS (`Documents/covers/`), originals fetched lazily on demand; iOS app proxies external URLs through the backend.

---

## Repo layout

```
gameTracker/
  ios/                      — Swift / SwiftUI iPhone app
    GameTracker/            — Xcode project (open the .xcodeproj here)
  api/                      — PHP API endpoints (v2 lives under api/v2/)
  includes/                 — PHP config + shared helpers
  js/, css/                 — web-app frontend
  uploads/                  — user-uploaded cover and item images
  database/                 — schema + migrations
  docs/superpowers/         — design specs + implementation plans for each feature
    specs/                  — *-design.md docs that were approved before coding
    plans/                  — *-implementation plans that drove each task
```

The `docs/superpowers/` tree is the design history — if you want to see how each iOS feature was scoped, brainstormed, and implemented, the matching spec and plan are both there.

---

## Quick start

### Web app + server

See **[SETUP-GUIDE.md](SETUP-GUIDE.md)** for the full setup walkthrough (Ubuntu, nginx, MySQL, TLS via Let's Encrypt, fail2ban, UniFi port forwarding).

### iOS app (development)

1. Open `ios/GameTracker/GameTracker.xcodeproj` in **Xcode 16+**.
2. Point `Config.serverBaseURL` at your deployed instance (defaults to `https://cammysgametracker.duckdns.org` — change this to your own).
3. Pick your iPhone (or an iPhone 17 simulator on iOS 18+) as the run destination.
4. ⌘R.

For sideloading onto a real device without a paid Apple Developer Program, use Xcode's free signing (7-day expiry) or AltStore.

---

## Security

- HTTPS/SSL with Let's Encrypt certificates
- Rate limiting (application + nginx levels)
- Fail2ban for brute-force protection
- SQL injection protection (prepared statements throughout)
- XSS protection (HTML escaping)
- CSRF protection (tokens + SameSite cookies)
- Secure file uploads (MIME validation, size limits, dimension checks)
- Session security (secure cookies, timeout, regeneration)
- Comprehensive security logging
- Search-engine indexing blocked (this is a private app)

See **[SECURITY-ASSESSMENT.md](SECURITY-ASSESSMENT.md)** for the full analysis.

---

## Default login

⚠️ Default credentials are created on first run. **Change them immediately** via the admin dashboard or `change-admin-credentials.php`.

---

## Documentation

- **[SETUP-GUIDE.md](SETUP-GUIDE.md)** — full server + web-app setup
- **[SECURITY-ASSESSMENT.md](SECURITY-ASSESSMENT.md)** — security analysis
- **[UNIFI-SETUP.md](UNIFI-SETUP.md)** — UniFi network setup
- **[docs/superpowers/specs/](docs/superpowers/specs/)** — design specs for each iOS feature
- **[docs/superpowers/plans/](docs/superpowers/plans/)** — implementation plans

---

## Credits

Built by [@CammyBlack02](https://github.com/CammyBlack02). iOS app and most of the v2 architecture pair-programmed with Claude (Anthropic) via Claude Code.

---

## License

Open source for personal use.
