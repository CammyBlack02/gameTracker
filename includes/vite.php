<?php
/**
 * Vite manifest lookup for phased frontend migration (Fable §3, phase 4e).
 *
 * Any JS entry point that has been migrated to Vite is served via this helper
 * so PHP emits the correct content-hashed URL. Entries not in the manifest
 * fall back to the raw source file, which keeps un-migrated sub-views working
 * and lets `npm run build` be optional in dev.
 */

/**
 * Emit a <script> tag for a JS entry.
 *
 * @param string $entry Source-relative path, e.g. 'js/spin-wheel.js'.
 * @return string HTML for a single <script> tag.
 */
function vite_asset(string $entry): string
{
    static $manifest = null;

    if ($manifest === null) {
        $manifestPath = __DIR__ . '/../js/dist/manifest.json';
        if (is_readable($manifestPath)) {
            $manifest = json_decode(file_get_contents($manifestPath), true) ?: [];
        } else {
            $manifest = [];
        }
    }

    if (isset($manifest[$entry]['file'])) {
        $url = '/js/dist/' . $manifest[$entry]['file'];
        return '<script type="module" src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></script>';
    }

    // Dev fallback: raw source, classic script. `npm run build` hasn't run,
    // or this entry isn't yet migrated to Vite.
    $url = '/' . ltrim($entry, '/');
    return '<script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></script>';
}
