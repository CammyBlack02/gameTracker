#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

PROJECT_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"

blue "Thumbnail helper: generates a thumbnail from a source image"

# Create a test source image (1024x1024 red square)
TMP_SRC="/tmp/v2_thumb_src.jpg"
TMP_DEST="/tmp/v2_thumb_dest.jpg"
rm -f "$TMP_SRC" "$TMP_DEST"

php -r '
$img = imagecreatetruecolor(1024, 1024);
$red = imagecolorallocate($img, 255, 0, 0);
imagefill($img, 0, 0, $red);
imagejpeg($img, "/tmp/v2_thumb_src.jpg", 90);
imagedestroy($img);
'

# Call the helper
php -r "
require '$PROJECT_ROOT/includes/thumbnail.php';
\$ok = gt_generate_thumbnail('/tmp/v2_thumb_src.jpg', '/tmp/v2_thumb_dest.jpg', 512);
exit(\$ok ? 0 : 1);
"

[[ -f "$TMP_DEST" ]] && green "  PASS: thumbnail file exists" && PASS_COUNT=$((PASS_COUNT+1)) || { red "  FAIL: thumbnail not created"; FAIL_COUNT=$((FAIL_COUNT+1)); }

# Verify size
SIZE=$(php -r 'list($w, $h) = getimagesize("/tmp/v2_thumb_dest.jpg"); echo max($w, $h);')
assert_eq "512" "$SIZE" "thumbnail longest edge is 512px"

# Verify file size is meaningfully smaller
SRC_BYTES=$(stat -f%z "/tmp/v2_thumb_src.jpg" 2>/dev/null || stat -c%s "/tmp/v2_thumb_src.jpg")
DEST_BYTES=$(stat -f%z "/tmp/v2_thumb_dest.jpg" 2>/dev/null || stat -c%s "/tmp/v2_thumb_dest.jpg")
if (( DEST_BYTES < SRC_BYTES )); then
  green "  PASS: thumbnail is smaller ($DEST_BYTES < $SRC_BYTES bytes)"
  PASS_COUNT=$((PASS_COUNT+1))
else
  red "  FAIL: thumbnail not smaller ($DEST_BYTES vs $SRC_BYTES)"
  FAIL_COUNT=$((FAIL_COUNT+1))
fi

summarize
