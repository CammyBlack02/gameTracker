<?php
/**
 * Thumbnail generation using the GD extension.
 *
 * Generates a JPEG thumbnail whose longest edge is $maxDimension pixels.
 * Aspect ratio is preserved. Quality fixed at 80 (good visual quality
 * with ~70% smaller file size than 95).
 *
 * Returns true on success, false on failure (logs the reason via error_log).
 */

function gt_generate_thumbnail(string $srcPath, string $destPath, int $maxDimension = 512): bool {
    if (!file_exists($srcPath)) {
        error_log("gt_generate_thumbnail: source missing $srcPath");
        return false;
    }
    $info = @getimagesize($srcPath);
    if ($info === false) {
        error_log("gt_generate_thumbnail: not an image $srcPath");
        return false;
    }
    [$srcW, $srcH, $type] = $info;

    // Load source.
    switch ($type) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($srcPath); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($srcPath); break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($srcPath); break;
        case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($srcPath); break;
        default:
            error_log("gt_generate_thumbnail: unsupported type $type");
            return false;
    }
    if (!$src) {
        error_log("gt_generate_thumbnail: failed to load $srcPath");
        return false;
    }

    // Calculate target dimensions, preserving aspect ratio.
    if ($srcW <= $maxDimension && $srcH <= $maxDimension) {
        $dstW = $srcW;
        $dstH = $srcH;
    } elseif ($srcW >= $srcH) {
        $dstW = $maxDimension;
        $dstH = (int)round($srcH * ($maxDimension / $srcW));
    } else {
        $dstH = $maxDimension;
        $dstW = (int)round($srcW * ($maxDimension / $srcH));
    }

    $dst = imagecreatetruecolor($dstW, $dstH);
    // Preserve transparency on PNG/GIF sources by filling white.
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);

    // Ensure destination directory exists.
    $dir = dirname($destPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $ok = imagejpeg($dst, $destPath, 80);
    imagedestroy($src);
    imagedestroy($dst);
    if (!$ok) {
        error_log("gt_generate_thumbnail: imagejpeg failed for $destPath");
    }
    return $ok;
}

/**
 * Given a path like 'uploads/covers/abc.jpg', returns the corresponding
 * thumbnail path: 'uploads/covers/thumbs/abc.jpg'.
 */
function gt_thumbnail_path(string $originalPath): string {
    $dir = dirname($originalPath);
    $name = basename($originalPath);
    return $dir . '/thumbs/' . $name;
}
