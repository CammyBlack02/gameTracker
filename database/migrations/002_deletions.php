<?php
/**
 * Migration 002: deletions table + triggers
 *
 * The deletions table is a tombstone log: when a row is deleted from a
 * synced table, a tombstone is inserted here so the iOS app's next delta
 * sync can learn about the deletion. Triggers do this automatically so
 * existing web-UI delete code paths need no changes.
 */
return function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS deletions (
        id INT PRIMARY KEY AUTO_INCREMENT,
        user_id INT NOT NULL,
        table_name VARCHAR(64) NOT NULL,
        server_id INT NOT NULL,
        deleted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_user_id_deleted (user_id, deleted_at),
        INDEX idx_table_server (table_name, server_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Triggers: drop-then-create so re-runs are safe.
    // (MySQL does not support CREATE TRIGGER IF NOT EXISTS in all versions.)
    $tables = [
        'games'           => 'OLD.user_id',
        'items'           => 'OLD.user_id',
        'game_completions'=> 'OLD.user_id',
        'game_images'     => 'OLD.user_id',
        'item_images'     => 'OLD.user_id',
    ];
    foreach ($tables as $table => $userIdExpr) {
        $trigger = "trg_{$table}_after_delete";
        $pdo->exec("DROP TRIGGER IF EXISTS $trigger");
        $pdo->exec("CREATE TRIGGER $trigger
            AFTER DELETE ON $table
            FOR EACH ROW
            INSERT INTO deletions (user_id, table_name, server_id)
            VALUES ($userIdExpr, '$table', OLD.id)");
    }
};
