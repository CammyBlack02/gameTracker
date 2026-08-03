<?php

namespace GameTracker\Cli;

use PDO;
use RuntimeException;

/**
 * Turns a --user reference (or the absence of one) into a concrete user row.
 *
 * Phase B services take an explicit int $userId, so this is the single place
 * that decides who the CLI is acting as. Guessing wrong would mean reading or
 * writing another user's collection, so an ambiguous case is an error rather
 * than a default.
 */
final class UserResolver
{
    public static function resolve(PDO $pdo, ?string $ref): array
    {
        if ($ref !== null && $ref !== '') {
            return self::byRef($pdo, $ref);
        }

        // No reference given. A single-user install has an unambiguous answer;
        // anything else must be stated explicitly.
        $users = $pdo->query('SELECT id, username, role FROM users ORDER BY id')->fetchAll();

        if (count($users) === 1) {
            return $users[0];
        }

        if ($users === []) {
            throw new RuntimeException('no users exist in this database');
        }

        $names = implode(', ', array_column($users, 'username'));
        throw new RuntimeException(
            "multiple users exist — pass --user=<username|id> (available: {$names})"
        );
    }

    private static function byRef(PDO $pdo, string $ref): array
    {
        $column = ctype_digit($ref) ? 'id' : 'username';

        $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE {$column} = ?");
        $stmt->execute([$ref]);
        $user = $stmt->fetch();

        if (!$user) {
            throw new RuntimeException("no such user: {$ref}");
        }

        return $user;
    }
}
