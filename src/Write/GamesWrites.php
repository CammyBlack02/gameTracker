<?php

namespace GameTracker\Write;

/**
 * Writable columns for games.
 *
 * id, user_id, created_at and updated_at are deliberately absent: identity and
 * ownership are not user-assignable, and the timestamps are maintained by the
 * database (updated_at is `on update CURRENT_TIMESTAMP`, which is what makes
 * CLI writes visible to iOS delta sync).
 */
final class GamesWrites
{
    public static function definition(): WriteDefinition
    {
        return new WriteDefinition(
            table: 'games',
            writable: [
                'title', 'platform', 'genre', 'description', 'series',
                'special_edition', 'condition', 'review', 'star_rating',
                'metacritic_rating', 'played', 'price_paid',
                'pricecharting_price', 'is_physical', 'digital_store',
                'release_date', 'front_cover_image', 'back_cover_image',
            ],
            booleans: ['played', 'is_physical'],
            notNull: ['title', 'platform'],
            requiredOnCreate: ['title', 'platform'],
        );
    }
}
