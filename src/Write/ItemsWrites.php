<?php

namespace GameTracker\Write;

/**
 * Writable columns for items.
 *
 * Deliberately not the games list: items has category, quantity and notes but
 * no played, is_physical or star_rating. There are no boolean columns here, so
 * a valueless --set-<column> is always a usage error on items.
 *
 * Note that platform is nullable on items while it is NOT NULL on games, so
 * items requires title and category on create rather than title and platform.
 */
final class ItemsWrites
{
    public static function definition(): WriteDefinition
    {
        return new WriteDefinition(
            table: 'items',
            writable: [
                'title', 'platform', 'category', 'description', 'condition',
                'price_paid', 'pricecharting_price', 'quantity', 'front_image',
                'back_image', 'notes',
            ],
            booleans: [],
            notNull: ['title', 'category'],
            requiredOnCreate: ['title', 'category'],
        );
    }
}
