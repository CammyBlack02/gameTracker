<?php

namespace GameTracker\Query;

/**
 * The items filter vocabulary.
 *
 * Deliberately not the games vocabulary: items has category and quantity but no
 * played, is_physical or star_rating. Sharing one global filter set would make
 * `gt items list --played` match nothing silently instead of telling the caller
 * the flag does not apply here.
 *
 * user_id is absent for the same reason as in GamesFilters.
 */
final class ItemsFilters
{
    public static function definition(): FilterDefinition
    {
        return new FilterDefinition(
            table: 'items',
            exact: [
                'platform' => 'platform',
                'category' => 'category',
                'condition' => 'condition',
            ],
            like: [
                'title-like' => 'title',
            ],
            booleans: [],
            ranges: [
                'added-since' => ['created_at', '>='],
                'added-before' => ['created_at', '<'],
            ],
            missingColumns: [
                'title', 'platform', 'category', 'description', 'condition',
                'price_paid', 'pricecharting_price', 'front_image',
                'back_image', 'notes', 'quantity',
            ],
            sortColumns: [
                'id', 'title', 'platform', 'category', 'price_paid',
                'quantity', 'created_at', 'updated_at',
            ],
            defaultSort: '-created_at',
        );
    }
}
