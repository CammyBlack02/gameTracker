# 3D Collection Store — Design Notes

**Status:** Draft. Brainstorm captured 2026-08-09. Nothing approved, nothing built.

## Overview

A walkable 3D retro game shop that renders the gameTracker collection — games,
consoles and accessories, physical and digital — as stock on shelves. Browse the
aisles, pull a case off the wall, flip it over and read the real back cover, and
find the collection metadata (condition, rating, completion) surfaced in-world
rather than in a modal.

This started as a "V2 frontend" brainstorm and turned into something else when
[Halcyon Video](https://github.com/halcyon-video/halcyon-video) surfaced: a
mature open-source implementation of almost exactly this idea, for movies. The
recommendation below is to fork it rather than build from scratch.

The web app is unaffected. This is an additional way to browse the collection,
not a replacement for the pages that already exist.

---

## Section 1: The find

Halcyon Video is a Vite + TypeScript + three.js app (optionally Tauri-wrapped)
that procedurally builds a period video rental store from a Jellyfin library.
~83,000 lines of TypeScript, three.js 0.184, Vite 6, TS 5.6, a test suite,
Docker deployment, a live demo, version 0.2.1 at the commit reviewed
(`fe10dd2`).

Facts verified by reading the source, not the marketing:

- **It already does games.** `src/romm.ts` (816 lines) is an optional second
  data source reading [RomM](https://github.com/rommapp/romm). It mirrors the
  Jellyseerr module's shape: unconfigured, every export no-ops and the game
  section never builds.
- **It has a games-only mode.** With `bb_games_only` on, the movies leave
  entirely and the games take the whole floor plan. From `src/games-only.ts`:
  each platform becomes a synthetic library, so `StorePlan` hatches aisles for
  it exactly like a movie library — its own gondola run, signboards reading
  SNES / GAME BOY ADVANCE / PLAYSTATION, its own browse cursor, endcaps, camera
  framing and clerk pathing. Internally "a game has been a Movie with
  `game: true` since T18," so nothing downstream needs a games branch.
- **Games get real back-cover art**, not a generated one. See Section 4.
- **Branding is data, not code.** Brand, logo, colours, themes, fixtures and
  sign art are files dropped into `public/user-assets/`, which is git-ignored by
  design.
- **A 2.5D mode** runs the same store as plain HTML/CSS for low-power devices.
- **Remote Play** streams the live store to a browser over its own TURN relay.

The store part — walkable room, shelf runs, floor-plan packer, cases, lighting,
navigation — does not care what is on the shelf. That is the overwhelming
majority of the code, and it is the part we would otherwise have to write.

### Why not build our own

We costed this before finding Halcyon. The rendering was never the hard part —
a game case is 12 triangles, and 500 games is ~6,000. The hard parts were
spine art, hardware models, navigation that isn't motion-sickness, and a texture
budget that doesn't kill mobile Safari. Halcyon has solved all four, plus four
decades of fit-out, eight measured-sun HDR skies and three floor plans we would
never have got to.

---

## Section 2: Licence position

**Halcyon is GPL-3.0. gameTracker has no LICENSE file** — `README.md` says "Open
source for personal use," which is not an OSI licence and is not enforceable as
written.

The relevant facts:

- GPL-3.0 obligations attach to **distribution**, not use. Running a modified
  copy at home, for one household, forever, triggers no obligations at all.
- It is GPL-3.0, **not AGPL-3.0**. AGPL would trigger on network use; GPL does
  not. Serving the store to our own browsers over our own LAN is not
  distribution.
- `cammyblack02/gameTracker` is a **public repo**. Pushing Halcyon-derived code
  there *is* distribution, and would make the combined work GPL-3.0.

**Decision: the fork lives in its own repository.** It is GPL-3.0, as required.
gameTracker's own licensing is untouched, and the boundary between them is an
HTTP API. This also happens to be the cleaner architecture.

**Action item:** gameTracker needs a real LICENSE file regardless of what
happens here. "Open source for personal use" should be replaced with something
that means something.

## Section 3: Fork, and what upstream expects

`CONTRIBUTING.md` is unusually direct, and it settles the question of whether
forking creates any obligation or exposure:

> Short version: **the source is open, the development is not. This project does
> not accept pull requests.**
>
> If you want this project to go a different direction, **fork it**. That's not a
> brush-off; it's the actual answer, and the license exists to make it a real one.

Pull requests are closed with a link to that file. Consequences for us:

- Upstream will not want our adapter. Nothing we write flows back.
- **gameTracker does not become any more public than it is today.** This was the
  explicit concern; it is unfounded. There is no upstream channel to leak
  through.
- Forking is the endorsed path, not a workaround.

`CONTRIBUTING.md` also carries an "If you're forking" section which is, in
effect, architecture guidance written for us:

- `src/three-scene.ts` is the spine — renderer, render-on-demand loop, mode state.
- Every feature lives in a `store-*.ts` module taking the scene as its first
  parameter. **New feature = new module.**
- New art = a file in an art slot (`public/user-assets/`; run
  `node tools/list-slots.mjs` for the manifest).
- New fixture = a registry entry plus a config placement.
- Performance is the prime directive: the app idles for days, so no per-frame
  allocations, dispose GPU resources, instance repeats.
- `npm run build` must pass — `tsc`, plus a file line-budget check
  (`tools/check-file-budget.mjs`) and signage-config validation.

### Fork discipline

The failure mode for a fork of this size is diverging until upstream rebases
stop happening, leaving us alone with 83,000 lines of someone else's TypeScript.
The mitigation is to follow the guidance above literally: **keep our changes in
new files.** New adapter module, new fixture for the hardware counter, new asset
folder. Every edit to an existing upstream file is a future merge conflict.

There is one hard rule from upstream worth repeating because it applies to our
asset work too: **do not commit third-party brand assets.** No logos,
wordmarks, vector traces, typefaces or store photography belonging to a real
chain. That is exactly why `public/user-assets/` is git-ignored.

---

## Section 4: Covers and case art

### Where art comes from

There is **no built-in art grabber**. Halcyon renders whatever the data source
gives it. RomM's adapter prefers art on RomM's own disk over the remote links it
recorded, with a documented reason: those `url_cover` links carry RomM's shared
ScreenScraper developer key, which has been rejected since 2026-07-28 and
returns a 59-byte error page instead of a cover.

For us this means **our existing cover system, unchanged** — `uploads/covers/`,
served by our own nginx.

### Back covers are already real art

The generated back cover — cream stock, synopsis window, cast list, rental
warnings — is the **movie** path, and exists because Jellyfin has no back art.
It is not what games get, and it is not what we want.

Games have their own module, `src/game-case-art.ts`:

> Flat scan art for a game case's NON-front faces — the back panel, the spine,
> and the label.

```ts
export type GameFace = 'back' | 'spine' | 'label';
```

The requirement — real back-cover art, no user ratings printed on the box — is
the existing default on the games path. Nothing needs changing to get it.

Depth-of-care signal, for anyone assessing whether this codebase is worth
inheriting: it detects PlayStation's two-flap spine scans, because the PS1 back
inlay is a single sheet with a printed flap on each side and mapping the whole
scan onto one face squeezes both onto one spine. Spine scan widths were measured
per platform (PlayStation 72px n=101, Saturn 37px, PS2/GameCube 55px, N64 97px,
3DS 59–63px) to work that out.

### Field mapping

| gameTracker | Halcyon | Notes |
|---|---|---|
| `games.title` | `title` | |
| `games.platform` | platform / synthetic library | Drives aisle grouping and signboards. `GamesService::platforms` already returns these with counts. |
| `front_cover_image` | cover | |
| `back_cover_image` | `GameArtUrls.back` | Real scan art on the back face. |
| — | `GameArtUrls.spine` | We have no spine art; falls back to generated. |
| — | `GameArtUrls.label` | Disc/cart label. Unused initially; `game_images` could feed it later. |
| `is_physical` / `digital_store` | — | See open questions. |
| `condition`, `star_rating`, `played`, `price_paid`, `game_completions` | — | Surfaced in-world, Section 6. |
| `items` (Console, Controller, …) | — | New fixture, Section 5. |

### Every title gets a box (decided 2026-08-09)

In the shop, **everything renders as a physical box** — the shop is a shop, and
a shop has boxes on shelves. Specifically:

- PC physical titles get a **PC DVD case**.
- Xbox and PlayStation titles get their respective platform cases (already
  handled — the case geometry is per-platform, and our own `getCaseType()` in
  `js/utils.js` carries the same idea in miniature).
- **Digital titles also get a box**, with a **"DIGITAL" sticker** on it.

This resolves the open question about `is_physical` / `digital_store` without
inventing a separate digital rack. It keeps the floor plan uniform, and the
sticker does the honest labelling. Digital games *are* part of the collection;
they just never had a case, so the shop prints one.

Note the sticker interaction with Section 6: condition and price are also
proposed as a shop sticker. Two stickers on one case needs a placement rule
(distinct corners, or a single combined label) so they don't collide or
obscure cover art. Worth deciding before either is built.

### Spine art (decided 2026-08-09)

**Generated placeholder spines for now.** Real spine scans exist for some
titles but are hard to source for every game, and the priority is front and
back covers — which we have, and which are the faces that carry the collection.

One risk to design around: **mixed availability looks worse than none.** A
shelf where three cases have real scanned spines and the rest are generated
reads as broken rather than as a work in progress. Two mitigations, either
acceptable:

- Style the generated spine so a real scan sitting beside it doesn't clash
  (platform colour band, title, consistent typography).
- Opt in per platform — use real spines only where we have them for *every*
  title on that run.

Face-out stocking (Section 7) also reduces how much the spine matters, since a
faced-out case shows its front, not its edge.

### Same-origin is a real win

Every RomM art URL gets wrapped as `/dev-proxy?art=<url>&auth=<header>` because
neither RomM nor the IGDB CDN answers CORS. Serving the store from our own nginx
alongside gameTracker makes our covers same-origin and that entire mechanism a
no-op.

---

## Section 5: Hardware — the one real content gap

Halcyon has no concept of hardware, because Jellyfin has none. Consoles,
controllers and accessories (`items`, keyed by `category`) are the most
gameTracker-specific part of this and the part we would have to design
ourselves.

Three surfaces, in priority order:

1. **The glass display counter.** Every retro game shop has one by the till,
   consoles and controllers under glass. Period-authentic, it is where hardware
   actually lives in a real shop, and it reuses existing fixture and
   poster-texture machinery rather than needing modelled hardware. Photos on
   cards under glass reads as deliberate, not cheap.
2. **Pegboard behind the counter.** Boxed accessories hanging on hooks. Same
   photo-on-a-plane treatment; absorbs whatever the counter can't hold.
3. **The catalog binder.** The laminated ring binder on the counter that you
   flip through — the full searchable list, in-world. This is where completions
   and ratings can live for real, since they have nowhere to sit on a shelf, and
   it solves "I actually need to look something up" without breaking the fiction.

---

## Section 6: Surfacing collection metadata

Requirement: condition, rating and completion must be reachable, but **not
printed on the back of the box** — the back is for real cover art.

`src/store-inspect.ts` already implements a front/back/spine **flip cycle** on a
picked-up case, so this extends an existing cycle rather than bolting a modal
onto a 3D scene.

Layered by how much the user wants to know:

- **Shop sticker on the case** — condition and price, printed like every retro
  shop stickers its stock. Passive, always visible, zero interaction, and the
  most authentic detail on the list.
- **The insert / leaflet** — open the case and the manual and insert slide out:
  star rating, completion date, `time_taken`, notes. Real cases have inserts, so
  nothing breaks. This is the full record.
- **Shelf-talkers** — the little cards under the shelf edge, for star ratings.
  There is existing staff-picks machinery (`tests/staff-picks.test.ts`) to hang
  this on.
- **The clerk** — `clerk-recommend.ts` and `recordInspect` already exist.
  `game_completions` could feed it: "you finished this one in 2019." Reuses
  something substantial for free.

### Ideas parked from the earlier brainstorm

These predate the Halcyon find and still apply, since they are all driven by
columns we have and Halcyon does not:

- **Time travel.** Every game has `created_at`. A scrubber fills the shop as the
  collection was acquired. Highest magic-to-effort ratio of anything discussed;
  needs no new data.
- **The backlog pile.** `played = 0` goes in a heap, dusty in proportion to how
  long it has sat there.
- **Value heatmap.** `price_paid` vs `pricecharting_price`, cases glowing by
  margin.
- **Console pedestals.** `items.platform` joins to `games.platform`; each console
  on a plinth in front of its own aisle.
- **Series gaps.** `games.series` exists; render a labelled hole in the shelf
  where a missing entry belongs. Needs external data to know what "complete"
  means — the expensive one.

---

## Section 7: Stock — filling the floor

Goal: the collection should fill the shop. A big empty store reads as broken; a
small full one reads as a real shop.

**The shop already sizes itself to the catalog, not the other way round.** This
was the main worry and it is largely handled upstream:

- `baselineStorefrontWidth()` / `baselineStoreDepth()` in `src/store-layout.ts`
  are explicitly the **"baseline (small-store)"** dimensions — the store grows
  from a small floor plan rather than starting large and needing filling.
- `TINY_LIBRARY_MOVIES = SECTION_CAPACITY * 2` = **60**. Below 60 titles, a
  library keeps one un-sectioned run instead of sprawling across signposted
  sections.
- `MIN_CATEGORY_TITLES = 6` — categories thinner than six titles flex into
  GENERAL shelves rather than getting their own half-empty sign.
- Floor plans are **packed to fit the room** across three arrangements
  (herringbone, straight, diagonal).

**Games-only mode works in our favour here.** Each platform becomes its own
synthetic library, so it gets its own contiguous shelf run, its own signboard
and its own endcaps — regardless of how few titles it holds. A collection
spread across a dozen platforms therefore occupies far more floor than the same
number of titles packed densely into one library. For scale: one double-sided
shelving unit is `UNIT_CAPACITY` = 120 cases, and one signboard section is
`SECTION_CAPACITY` = 30.

**Thin sections are already filled with face-out copies.** From
`store-layout.ts`, on slots a category cannot fill on its own:

> used to be bare `null`s. Instead we face-out extra copies of that category's
> *most deserving* titles (real stores stack multiples of a hot title together).

There is a sort comparator deciding which titles earn filler copies, a cap on
how many adjacent copies of one title a fill run places, and separate backstock
stacking (`extraCopiesCount()`) that puts copies *behind* the face copy. This is
exactly what a real under-stocked shop does, and it plays to our strengths:
face-out shows front covers, which are our best asset, and hides spines, which
are our weakest.

If more floor still needs filling after that, in rough order of authenticity:

1. **Non-game stock we already have.** The hardware counter and pegboard
   (Section 5) are floor and wall space that costs no games at all.
2. **Period set dressing.** There is an `ambient-tvs.ts` module (917 lines) —
   CRT TVs playing attract loops are the single most game-shop thing available,
   and they consume wall and floor without needing stock.
3. **A bargain bin.** Upstream already has one for the worst-rated titles. Our
   equivalent could be low `star_rating`, or `condition` = loose/poor.
4. **The completions wall.** `game_completions.completion_year` as plaques —
   uses wall space, which shelving doesn't compete for.
5. **Turn the deficit into the feature.** Empty shelf space labelled as
   wishlist or series gaps (`games.series`). A shop with visible holes where
   Final Fantasy VIII should go is more interesting than a shop padded with
   duplicates.
6. **The back room.** `back-room.ts` (1,313 lines) exists upstream. A stockroom
   is a plausible home for digital titles or the unplayed backlog if we ever
   want them off the shop floor.

Realistically, Step 0 and Step 1 answer this better than any estimate here —
walk the demo, then walk it with our own collection loaded.

## Section 8: Hosting

**Decision: browser, served as static files from our own nginx, alongside
gameTracker.**

- It is already a browser app. The Tauri wrapper exists to shell out to
  emulators (`romm_launch_cmd` is Tauri-only). We launch nothing, so we don't
  need Tauri.
- Rendering is client-side, so the intermittently-powered server laptop is not
  the bottleneck — it serves static files and JSON.
- **Not Remote Play.** That is server-side rendering streamed over TURN and
  would want a GPU in the server. Wrong shape for our hardware.
- Same-origin, per Section 4.

Expectation-setting: this is a desktop-and-TV experience. Upstream's own README
warns that ~2,000 titles wants a few GB of memory and real GPU use, and that it
is built for a dedicated HTPC. Our collection is far smaller, but this is not
the thing to open on a phone in a shop to check whether we already own
something. **That remains gameTracker's normal web UI**, and that division of
labour is the intended one. 2.5D mode covers the low-power case if we want the
store on a phone at all.

---

## Section 9: The ladder

Each rung is cheap and kills a question.

**Step 0 — walk the demo.** https://halcyon-video.github.io/halcyon-video/ —
no Jellyfin, no RomM, no signup. `src/demo-library.ts`'s `buildDemoGames()`
synthesises a games department across SNES, Genesis, N64, PlayStation and GBA,
and games-only mode works against it. Caveat: demo game covers are generated
placeholders, so it shows the *store* but not real back-scan art quality.
Everything below is downstream of this.

**Step 1 — the RomM-shim spike. No fork, roughly a day.** Add an endpoint to
gameTracker returning RomM-shaped JSON, point stock Halcyon at it, enable
games-only. Our games on their shelves, with our front *and* back covers (RomM
carries `box2d_back_path`, so the shim can emit it). Loses ratings, condition
and hardware at the adapter boundary — fine, this rung is not the destination.
It is the cheapest proof that the idea holds up with our own collection in it
rather than a demo library.

**Step 2 — fork, native adapter.** Own repo, GPL-3.0. A `gametracker.ts` data
source module mirroring `romm.ts`'s shape. Full fidelity: condition, ratings,
completions, physical vs digital, plus the hardware counter from Section 5 and
the metadata layering from Section 6.

**Step 3 — reskin to a retro game shop.** Mostly asset work in
`public/user-assets/`: logo, sign art, colours. The 1990 era preset with board
signage is already close. Note that with games-only enabled, the floor is
*already* a game shop — only the branding still says video rental.

---

## Section 10: What survives independently

None of these depend on any of the above, and all remain worth doing:

- **CoverFlow in three.js**, inside gameTracker. The current
  `js/render/coverflow.js` fakes box thickness with three `coverflow-box-edge-*`
  divs because CSS can rotate a plane but cannot make a solid. Real geometry
  gets actual edges, a real flip, and a visible spine. Note that the **iOS app
  already did this** — `docs/superpowers/specs/2026-05-22-ios-coverflow-design.md`
  specifies real 3D boxes with visible spines in SceneKit, and made every hard
  call already (focused box front-facing, sides at ~45°, cart vs disc
  proportions, GameCube as a full DVD box, tap navigates rather than opens).
  Port those decisions; don't re-derive them. This is a view inside our own app
  next to grid and list, which is a different product from a full-screen store
  you launch on a TV.
- **Tier 0 polish** — platform-derived accent palettes (the CSS already carries
  ~30 `[data-platform*="…"]` selectors), staggered card entrance, View
  Transitions for cover → detail, a Cmd-K palette over the already-in-memory
  collection.
- **The v1/v2 API convergence** — more relevant now, not less. A RomM-compat
  endpoint is exactly the thin-wrapper-over-`GamesService` shape v2 is built
  for. Reads are already unified (`GamesService::list` serves v1, v2 and the
  CLI); writes are not (`GamesWriter` is CLI-only, `api/games.php` and
  `api/v2/sync/push.php` each carry their own SQL). One live consequence:
  `gt undo` cannot revert an edit made from the web UI or the phone.

---

## Decided

- **Digital games get a box with a "DIGITAL" sticker** (Section 4). No separate
  rack. PC physicals get a PC DVD case; Xbox and PlayStation get their platform
  cases.
- **Spine art is the generated placeholder for now** (Section 4). Front and back
  covers are the priority; real spine scans are too hard to source for every
  title.
- **The fork lives in its own repo**, GPL-3.0, talking to gameTracker over HTTP
  (Section 2).
- **Browser-hosted, served from our own nginx**, not Tauri and not Remote Play
  (Section 8).

## Open questions

1. **Sticker placement.** Digital and condition/price are both proposed as
   stickers on the case. Distinct corners, or one combined label? Needs deciding
   before either is built.
2. **Mixed spine availability.** If real spine scans get added for some titles
   later, a run of mixed real and generated spines looks worse than all
   generated. Style-to-match, or opt in per platform. See Section 4.
3. **Multi-user.** gameTracker is multi-user; the store assumes one catalog.
   Whose collection does it show, and is a shared household store meaningful?
4. **Collection size vs floor plan.** Largely answered in Section 7 — the store
   sizes from a small-store baseline, thin sections self-fill with face-out
   copies, and games-only mode gives every platform its own run. Confirm
   empirically at Step 0 and Step 1 rather than estimating further.
5. **gameTracker's LICENSE file.** Independent of everything else. Needs doing.

---

## References

- Upstream: https://github.com/halcyon-video/halcyon-video (GPL-3.0, `fe10dd2`)
- Demo: https://halcyon-video.github.io/halcyon-video/
- RomM: https://github.com/rommapp/romm
- `docs/superpowers/specs/2026-05-22-ios-coverflow-design.md` — prior art for
  3D game cases with spines, already shipped on iOS.
- `FABLE-SUGGESTIONS.md` §3 (frontend), §6 (performance).
