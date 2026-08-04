<?php

namespace GameTracker\Import;

/**
 * The comparison key used to decide whether an imported title is one the user
 * already owns.
 *
 * Deliberately conservative. Stripping too much makes distinct games collide,
 * and a collision means a game is silently never imported — a failure that
 * looks identical to "already owned". So this removes only noise that carries
 * no meaning: trademark furniture, case, and redundant whitespace. Punctuation
 * stays, because "Half-Life 2" and "Half-Life 2: Deathmatch" are different
 * games and the colon is what says so.
 *
 * No normalised column is stored anywhere, so this can change without a
 * migration or a stale cache.
 */
final class TitleKey
{
    public static function normalise(string $title): string
    {
        // Trademark furniture varies between storefronts for the same game.
        $clean = str_replace(["\u{2122}", "\u{00AE}", "\u{00A9}"], '', $title);

        // Any run of whitespace, including tabs and non-breaking spaces from
        // CSV exports, becomes one plain space.
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;

        // mb_strtolower, not strtolower: the latter mangles multibyte
        // characters, so "Pokémon" would not survive intact.
        return mb_strtolower(trim($clean), 'UTF-8');
    }
}
