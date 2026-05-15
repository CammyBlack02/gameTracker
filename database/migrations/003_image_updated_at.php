<?php
/**
 * Migration 003: add updated_at to game_images and item_images.
 *
 * These tables don't currently have updated_at, so the iOS sync engine
 * can't tell whether an extra photo row has been modified server-side.
 * Adding the column with ON UPDATE CURRENT_TIMESTAMP makes it
 * self-maintaining.
 */
return function (PDO $pdo): void {
    foreach (['game_images', 'item_images'] as $table) {
        try {
            $pdo->exec("ALTER TABLE $table
                ADD COLUMN updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP");
        } catch (PDOException $e) {
            // Column already exists — ignore (matches project convention).
            if (strpos($e->getMessage(), 'Duplicate column') === false) {
                throw $e;
            }
        }
        try {
            $pdo->exec("ALTER TABLE $table ADD INDEX idx_updated_at (updated_at)");
        } catch (PDOException $e) {
            // Index already exists — ignore.
        }
    }
};
