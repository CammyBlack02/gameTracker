#!/usr/bin/env bash
# Slim store projection (/api/v2/games/store.php) and the RomM-compatibility
# shim (/api/romm/*) that Step 1 of the 3D store points Halcyon Video at.
#
# Endpoints are hit at their REAL file paths here, not the /store-shim mount:
# the extensionless mount is an nginx rewrite (nginx-gameTracker.conf) and this
# harness runs under `php -S`, which has no such rewrite. The rewrite maps
# /store-shim/api/{platforms,roms} onto exactly these two files, so testing the
# files tests the behaviour; only the mapping itself is uncovered.
#
# See docs/superpowers/specs/2026-08-09-3d-collection-store-design.md
source "$(dirname "$0")/lib.sh"

blue "Setup: mint a Bearer token"
req POST "/api/v2/auth/token.php" "username=$TEST_USER&password=$TEST_PASS&device_name=store-test"
assert_eq "200" "$HTTP_STATUS" "token minted"
TOKEN=$(printf '%s' "$RESPONSE_BODY" | sed -n 's/.*"token"[[:space:]]*:[[:space:]]*"\([0-9a-f]\{64\}\)".*/\1/p')
if [[ -z "$TOKEN" ]]; then
  red "could not extract token from: $RESPONSE_BODY"
  exit 1
fi

AUTH=(-H "Authorization: Bearer $TOKEN")

# --- Auth is enforced ---------------------------------------------------------
blue "Unauthenticated access is refused"

req GET "/api/v2/games/store.php"
assert_eq "401" "$HTTP_STATUS" "store.php without a token = 401"

req GET "/api/romm/platforms.php"
assert_eq "401" "$HTTP_STATUS" "romm platforms without a token = 401"

req GET "/api/romm/roms.php?platform_ids=1"
assert_eq "401" "$HTTP_STATUS" "romm roms without a token = 401"

# --- Method guard -------------------------------------------------------------
blue "Mutating methods are refused"

req POST "/api/v2/games/store.php" "" "${AUTH[@]}"
assert_eq "405" "$HTTP_STATUS" "store.php POST = 405"

req POST "/api/romm/platforms.php" "" "${AUTH[@]}"
assert_eq "405" "$HTTP_STATUS" "romm platforms POST = 405"

# --- Slim projection ----------------------------------------------------------
blue "store.php returns the slim projection"

req GET "/api/v2/games/store.php" "" "${AUTH[@]}"
assert_eq "200" "$HTTP_STATUS" "store.php = 200"
assert_contains '"data"' "$RESPONSE_BODY" "v2 envelope"
assert_contains '"platforms"' "$RESPONSE_BODY" "carries platforms"
assert_contains '"games"' "$RESPONSE_BODY" "carries games"
assert_contains '"total"' "$RESPONSE_BODY" "carries a total"
assert_contains '"platform_id"' "$RESPONSE_BODY" "platform_id is projected"

# The whole point of the slim projection: the heavy prose columns stay home.
# If these ever appear, the store is paying for bytes it cannot draw.
if printf '%s' "$RESPONSE_BODY" | grep -q '"description"'; then
  red "FAIL: store projection leaked description"
  exit 1
fi
green "  ✓ description is not projected"
if printf '%s' "$RESPONSE_BODY" | grep -q '"review"'; then
  red "FAIL: store projection leaked review"
  exit 1
fi
green "  ✓ review is not projected"
if printf '%s' "$RESPONSE_BODY" | grep -q '"price_paid"'; then
  red "FAIL: store projection leaked price_paid"
  exit 1
fi
green "  ✓ price_paid is not projected"

# --- RomM platform contract ---------------------------------------------------
blue "romm platforms answers RomM's shape"

req GET "/api/romm/platforms.php" "" "${AUTH[@]}"
assert_eq "200" "$HTTP_STATUS" "romm platforms = 200"
# Bare array, NOT a {"data": …} envelope — romm.ts parses the body as payload.
assert_contains '[' "$RESPONSE_BODY" "bare array, no v2 envelope"
if printf '%s' "$RESPONSE_BODY" | grep -q '"data"'; then
  red "FAIL: romm shim must not use the v2 envelope"
  exit 1
fi
green "  ✓ no v2 envelope"

# romm.ts drops any platform whose id is not a NUMBER (typeof check) or whose
# rom_count is 0. Either mistake makes the aisle silently never build, which is
# invisible from inside the store — so pin both.
if ! printf '%s' "$RESPONSE_BODY" | grep -qE '"id":[0-9]+'; then
  red "FAIL: platform id must be an unquoted number: $RESPONSE_BODY"
  exit 1
fi
green "  ✓ platform id is numeric"
if printf '%s' "$RESPONSE_BODY" | grep -qE '"rom_count":0([,}]|$)'; then
  red "FAIL: a zero rom_count platform would be dropped by the client"
  exit 1
fi
green "  ✓ no zero-count platforms"
assert_contains '"rom_count"' "$RESPONSE_BODY" "rom_count is present"
assert_contains '"slug"' "$RESPONSE_BODY" "slug is present"

# --- RomM rom contract --------------------------------------------------------
blue "romm roms answers RomM's shape"

PLATFORM_ID=$(printf '%s' "$RESPONSE_BODY" | sed -n 's/.*"id":\([0-9]\{1,\}\).*/\1/p' | head -1)
if [[ -z "$PLATFORM_ID" ]]; then
  red "no platform id to query with (seed data missing?)"
  exit 1
fi

req GET "/api/romm/roms.php?platform_ids=$PLATFORM_ID&limit=2000&offset=0&order_by=fs_name&order_dir=asc" "" "${AUTH[@]}"
assert_eq "200" "$HTTP_STATUS" "romm roms = 200"
assert_contains '"items"' "$RESPONSE_BODY" "items envelope"
assert_contains '"platform_id":'"$PLATFORM_ID" "$RESPONSE_BODY" "rom echoes the requested platform_id"
assert_contains '"fs_name"' "$RESPONSE_BODY" "fs_name is present"

# The spine decision, pinned: we hold no spine art, and romm.ts only skips the
# face when BOTH the _path and _url keys are absent. An empty string here would
# be treated as a real URL and paint a broken face onto every case.
if printf '%s' "$RESPONSE_BODY" | grep -q 'box2d_side'; then
  red "FAIL: shim must not emit a spine key — no spine art exists"
  exit 1
fi
green "  ✓ no spine key (generated fallback stays in play)"

# --- Unknown / absent platform ------------------------------------------------
blue "Unknown platform ids answer empty, not everything"

req GET "/api/romm/roms.php?platform_ids=999999999" "" "${AUTH[@]}"
assert_eq "200" "$HTTP_STATUS" "unknown platform = 200"
assert_contains '"items":[]' "$RESPONSE_BODY" "unknown platform = empty items"

req GET "/api/romm/roms.php" "" "${AUTH[@]}"
assert_eq "200" "$HTTP_STATUS" "missing platform_ids = 200"
assert_contains '"items":[]' "$RESPONSE_BODY" "missing platform_ids = empty items, never a full dump"

# --- Paging terminates --------------------------------------------------------
# romm.ts's games-only path loops on offset until a SHORT page comes back. A
# shim that ignored offset would return a full page forever and hang the boot.
blue "Paging honours offset so the client's loop terminates"

req GET "/api/romm/roms.php?platform_ids=$PLATFORM_ID&limit=1&offset=0" "" "${AUTH[@]}"
FIRST_PAGE="$RESPONSE_BODY"
req GET "/api/romm/roms.php?platform_ids=$PLATFORM_ID&limit=1&offset=100000" "" "${AUTH[@]}"
assert_contains '"items":[]' "$RESPONSE_BODY" "a far offset returns a short page"
if [[ "$FIRST_PAGE" == "$RESPONSE_BODY" ]]; then
  red "FAIL: offset is ignored — the client's paging loop would never terminate"
  exit 1
fi
green "  ✓ offset changes the page"

summarize
