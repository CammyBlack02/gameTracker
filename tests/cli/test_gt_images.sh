#!/usr/bin/env bash
# Image storage-mode classification and reconciliation.
#
# Design: docs/superpowers/specs/2026-08-04-gt-cli-images-design.md
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

# Case must not decide it either — a storefront may send DATA: or HTTPS://.
assert_eq "data-uri" "$(mode 'DATA:image/png;base64,AAAA')" "uppercase data: scheme"
assert_eq "url"      "$(mode 'HTTPS://example.com/a.jpg')"  "uppercase https scheme"

summarize
