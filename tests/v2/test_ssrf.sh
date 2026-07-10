#!/usr/bin/env bash
# SSRF regression tests — verify no fetch site accepts internal or
# reserved IPs. Written to fail against the pre-Phase-1 code and pass
# once includes/http-fetch.php is in place.
source "$(dirname "$0")/lib.sh"

blue "SSRF: image-proxy blocks cloud-metadata IP literal"
req GET "/api/image-proxy.php?url=https://169.254.169.254/latest/meta-data/"
assert_eq "403" "$HTTP_STATUS" "image-proxy blocks 169.254.169.254"

blue "SSRF: image-proxy blocks 0.0.0.0"
req GET "/api/image-proxy.php?url=https://0.0.0.0/"
assert_eq "403" "$HTTP_STATUS" "image-proxy blocks 0.0.0.0"

blue "SSRF: image-proxy blocks loopback via 127.0.0.1"
req GET "/api/image-proxy.php?url=https://127.0.0.1/"
assert_eq "403" "$HTTP_STATUS" "image-proxy blocks 127.0.0.1"

blue "SSRF: image-proxy allows a public host (placeholder that always resolves)"
# example.com is IANA-reserved and always resolves to public IPs; we just check
# the SSRF gate lets us past — 200/404/etc from example.com is fine.
req GET "/api/image-proxy.php?url=https://example.com/nothing.jpg"
if [[ "$HTTP_STATUS" != "403" ]]; then
  green "  PASS: example.com not blocked (HTTP $HTTP_STATUS)"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: example.com wrongly blocked"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

blue "SSRF: download-cover.php requires auth"
req GET "/api/download-cover.php?url=https://169.254.169.254/"
# auth-check.php redirects unauthenticated to /index.php (302).
if [[ "$HTTP_STATUS" == "302" || "$HTTP_STATUS" == "401" || "$HTTP_STATUS" == "403" ]]; then
  green "  PASS: no-auth blocked (HTTP $HTTP_STATUS)"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: no-auth status=$HTTP_STATUS"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

blue "SSRF: download-cover.php blocks metadata IP for authed user"
COOKIE=$(mktemp)
curl -sS -c "$COOKIE" -X POST "$BASE_URL/api/auth.php?action=login" \
  -d "username=$TEST_USER&password=$TEST_PASS" > /dev/null
req GET "/api/download-cover.php?url=https://169.254.169.254/" "" -b "$COOKIE"
assert_eq "400" "$HTTP_STATUS" "download-cover blocks 169.254 for authed user"

blue "SSRF: download-cover.php blocks 127.0.0.1 for authed user"
req GET "/api/download-cover.php?url=https://127.0.0.1/" "" -b "$COOKIE"
assert_eq "400" "$HTTP_STATUS" "download-cover blocks 127.0.0.1 for authed user"

rm -f "$COOKIE"

summarize
