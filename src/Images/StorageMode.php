<?php

namespace GameTracker\Images;

/**
 * What an image column is actually holding.
 *
 * The single place this decision is made. Every audit and every filesystem
 * check must branch on it first: only a filename lives on disk, and treating
 * the other kinds as paths is precisely the bug that produced the wrong
 * 2026-08-03 figures — base64 contains '/', so a basename of a data URI yields
 * a plausible-looking filename such as "9k=" which is then reported missing.
 * That artefact invented 42 broken covers that were never broken.
 */
final class StorageMode
{
    public const DATA_URI = 'data-uri';
    public const URL      = 'url';
    public const FILENAME = 'filename';
    public const EMPTY    = 'empty';

    public static function of(?string $value): string
    {
        $value = $value === null ? '' : trim($value);

        if ($value === '') {
            return self::EMPTY;
        }

        // Case-insensitive: a storefront or a paste may supply DATA: or HTTPS://,
        // and misclassifying one as a filename is how an inline image ends up
        // being stat()ed against the uploads directory.
        if (stripos($value, 'data:') === 0) {
            return self::DATA_URI;
        }

        if (stripos($value, 'http://') === 0 || stripos($value, 'https://') === 0) {
            return self::URL;
        }

        return self::FILENAME;
    }

    /** True only for values that correspond to a file under uploads/. */
    public static function isFile(?string $value): bool
    {
        return self::of($value) === self::FILENAME;
    }
}
