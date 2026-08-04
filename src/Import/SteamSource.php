<?php

namespace GameTracker\Import;

use GameTracker\Domain\BadRequestException;
use PDO;

/**
 * The user's Steam library as ImportRows.
 *
 * Detail lookups are best-effort: Steam's appdetails endpoint is rate-limited
 * and frequently unavailable, and losing a release date is not a reason to
 * abandon an import of 300 games. A row always survives a detail failure with
 * whatever the library listing gave us.
 */
final class SteamSource implements Source
{
    private int $count = 0;

    public function __construct(
        private readonly HttpTransport $http,
        private readonly string $apiKey,
        private readonly string $steamId,
    ) {
    }

    /**
     * @return array{key: string, id: string}
     */
    public static function credentialsFor(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT `setting_key`, `setting_value` FROM settings
             WHERE `user_id` = ? AND `setting_key` IN ('steam_api_key', 'steam_user_id')"
        );
        $stmt->execute([$userId]);

        $found = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $found[$row['setting_key']] = (string)$row['setting_value'];
        }

        $key = trim($found['steam_api_key'] ?? '');
        $id = trim($found['steam_user_id'] ?? '');

        if ($key === '' || $id === '') {
            throw new BadRequestException(
                'Steam credentials are not configured — set steam_api_key and '
                . 'steam_user_id in settings before importing'
            );
        }

        return ['key' => $key, 'id' => $id];
    }

    public function describe(): string
    {
        return "Steam library ({$this->count} games)";
    }

    /**
     * Steam's library listing has nothing to skip — every entry is a game the
     * user owns. Always zero, but implemented because Source requires it.
     */
    public function skipped(): int
    {
        return 0;
    }

    public function rows(): iterable
    {
        $url = 'https://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/'
             . '?key=' . urlencode($this->apiKey)
             . '&steamid=' . urlencode($this->steamId)
             . '&format=json&include_appinfo=1';

        $body = $this->http->get($url);

        if ($body === null) {
            throw new BadRequestException(
                'could not reach the Steam API — check the key, the Steam ID, and connectivity'
            );
        }

        $data = json_decode($body, true);
        $games = $data['response']['games'] ?? null;

        if (!is_array($games)) {
            throw new BadRequestException(
                'Steam returned no game list — is the profile private?'
            );
        }

        $this->count = count($games);

        foreach ($games as $game) {
            $name = trim((string)($game['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $appId = (int)($game['appid'] ?? 0);

            $columns = [
                'title' => $name,
                'platform' => 'PC',
                'digital_store' => 'Steam',
            ];

            $columns += $this->detail($appId);

            yield new ImportRow('games', $columns, $this->coverUrl($appId));
        }
    }

    /**
     * Best-effort extra fields. Any failure returns nothing rather than
     * throwing — see the class docblock.
     *
     * @return array<string, string>
     */
    private function detail(int $appId): array
    {
        if ($appId <= 0) {
            return [];
        }

        $body = $this->http->get("https://store.steampowered.com/api/appdetails?appids={$appId}");
        if ($body === null) {
            return [];
        }

        $data = json_decode($body, true);
        $payload = $data[$appId]['data'] ?? null;

        if (!is_array($payload)) {
            return [];
        }

        $extra = [];

        $description = trim((string)($payload['short_description'] ?? ''));
        if ($description !== '') {
            $extra['description'] = $description;
        }

        $released = $payload['release_date']['date'] ?? null;
        if (is_string($released) && $released !== '') {
            $timestamp = strtotime($released);
            if ($timestamp !== false) {
                $extra['release_date'] = date('Y-m-d', $timestamp);
            }
        }

        $genres = $payload['genres'] ?? [];
        if (is_array($genres) && isset($genres[0]['description'])) {
            $extra['genre'] = (string)$genres[0]['description'];
        }

        return $extra;
    }

    private function coverUrl(int $appId): ?string
    {
        if ($appId <= 0) {
            return null;
        }

        return "https://cdn.cloudflare.steamstatic.com/steam/apps/{$appId}/header.jpg";
    }
}
