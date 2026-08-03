<?php

namespace GameTracker\Query;

/**
 * The games filter vocabulary.
 *
 * user_id is deliberately absent from every list. Filtering by it would
 * reintroduce the cross-user override that was removed from the list endpoint
 * as an IDOR (Fable §1); scoping belongs to the service's explicit $userId.
 */
final class GamesFilters
{
    public static function definition(): FilterDefinition
    {
        return new FilterDefinition(
            table: 'games',
            exact: [
                'platform' => 'platform',
                'genre' => 'genre',
                'series' => 'series',
                'condition' => 'condition',
                'digital-store' => 'digital_store',
            ],
            like: [
                'title-like' => 'title',
            ],
            booleans: [
                'played' => ['played', true],
                'unplayed' => ['played', false],
                'physical' => ['is_physical', true],
                'digital' => ['is_physical', false],
            ],
            ranges: [
                'rating-min' => ['star_rating', '>='],
                'rating-max' => ['star_rating', '<='],
                'added-since' => ['created_at', '>='],
                'added-before' => ['created_at', '<'],
            ],
            missingColumns: [
                'title', 'platform', 'genre', 'description', 'series',
                'special_edition', 'condition', 'review', 'star_rating',
                'metacritic_rating', 'price_paid', 'pricecharting_price',
                'digital_store', 'front_cover_image', 'back_cover_image',
                'release_date',
            ],
            sortColumns: [
                'id', 'title', 'platform', 'genre', 'series', 'star_rating',
                'metacritic_rating', 'price_paid', 'created_at', 'updated_at',
                'release_date',
            ],
            defaultSort: '-created_at',
        );
    }
}
