#!/usr/bin/env bash
# Read-only image audit, v2.
#
# v1 was wrong: it ran `sed 's#.*/##'` over every value to take a basename.
# Base64 data URIs contain '/', so that turned an inline image into its tail
# ("/9k=" -> "9k=") and then reported it as a filename whose file was missing.
# Only genuine filename references are considered here: not data: URIs, not
# http(s) URLs.
set -uo pipefail
cd /var/www/gameTracker || exit 1
S=/tmp/claude-1000/-home-cammyblack02/b52b9252-35d2-4707-82f1-0df1641ecc60/scratchpad

q() { mysql gameTracker -N -B -e "$1"; }

FILTER="IS NOT NULL AND %s <> '' AND %s NOT LIKE 'data:%%' AND %s NOT LIKE 'http%%'"

sql=""
for spec in "games front_cover_image" "games back_cover_image" \
            "items front_image" "items back_image" \
            "game_images image_path" "item_images image_path"; do
  set -- $spec
  t=$1; c=$2
  [ -n "$sql" ] && sql="$sql UNION ALL "
  sql="$sql SELECT \`$c\` FROM $t WHERE \`$c\` IS NOT NULL AND \`$c\` <> '' AND \`$c\` NOT LIKE 'data:%' AND \`$c\` NOT LIKE 'http%'"
done

q "$sql" | sort -u > "$S/ref2.txt"

find uploads/covers uploads/extras -type f -not -path '*/thumbs/*' -printf '%f\n' 2>/dev/null | sort -u > "$S/disk2.txt"
find uploads/covers uploads/extras -type f -path '*/thumbs/*' -printf '%f\n' 2>/dev/null | sort -u > "$S/thumb2.txt"

comm -23 "$S/disk2.txt" "$S/ref2.txt" > "$S/orphans2.txt"
comm -13 "$S/disk2.txt" "$S/ref2.txt" > "$S/missing2.txt"

echo "referenced filenames (unique): $(wc -l < "$S/ref2.txt")"
echo "on disk, excl thumbs:          $(wc -l < "$S/disk2.txt")"
echo "thumbnails:                    $(wc -l < "$S/thumb2.txt")"
echo "orphans (disk, unreferenced):  $(wc -l < "$S/orphans2.txt")"
echo "missing (referenced, absent):  $(wc -l < "$S/missing2.txt")"
echo
echo "--- storage mode per column ---"
for spec in "games front_cover_image" "games back_cover_image" \
            "items front_image" "items back_image" \
            "game_images image_path" "item_images image_path"; do
  set -- $spec
  t=$1; c=$2
  q "SELECT CONCAT('  $t.$c: ',
       SUM(\`$c\` LIKE 'data:%'), ' data-uri, ',
       SUM(\`$c\` LIKE 'http%'), ' url, ',
       SUM(\`$c\` NOT LIKE 'data:%' AND \`$c\` NOT LIKE 'http%' AND \`$c\` IS NOT NULL AND \`$c\` <> ''), ' filename')
     FROM $t"
done
echo
echo "--- rows whose filename reference is genuinely absent from disk ---"
for spec in "games front_cover_image" "games back_cover_image" \
            "items front_image" "items back_image" \
            "game_images image_path" "item_images image_path"; do
  set -- $spec
  t=$1; c=$2
  n=0
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    if [ ! -f "uploads/covers/$f" ] && [ ! -f "uploads/extras/$f" ]; then n=$((n+1)); fi
  done < <(q "SELECT \`$c\` FROM $t WHERE \`$c\` IS NOT NULL AND \`$c\` <> '' AND \`$c\` NOT LIKE 'data:%' AND \`$c\` NOT LIKE 'http%'")
  echo "  $t.$c: $n rows broken"
done
