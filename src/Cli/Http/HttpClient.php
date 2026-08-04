<?php

namespace GameTracker\Cli\Http;

use GameTracker\Domain\BadRequestException;
use GameTracker\Domain\NotFoundException;

/**
 * Drives the v2 endpoints over HTTP for `gt --http`.
 *
 * Exists so the CLI can exercise the same code the iOS app reaches, rather
 * than only the in-process path. That is what makes the parity suite
 * meaningful: if this quietly fell back to calling services directly, every
 * parity assertion would pass while proving nothing.
 */
final class HttpClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    /**
     * Build from the environment.
     *
     * A bearer token rather than a login command: the CLI then holds no
     * password, stores no credential, and has no token file to secure.
     * Consistent with GT_USER, GT_JOURNAL_DIR and GT_TRASH_DIR.
     */
    public static function fromEnvironment(): self
    {
        $token = getenv('GT_TOKEN');

        if (!is_string($token) || trim($token) === '') {
            throw new BadRequestException(
                'GT_TOKEN is not set — --http needs a v2 bearer token. '
                . 'Mint one with POST /api/v2/auth/token.php'
            );
        }

        $base = getenv('GT_BASE_URL');
        if (!is_string($base) || $base === '') {
            $base = 'https://localhost';
        }

        return new self(rtrim($base, '/'), trim($token));
    }

    /**
     * GET a v2 endpoint and return the decoded `data` payload.
     *
     * @param array<string, string|bool> $query
     */
    public function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . '/' . ltrim($path, '/');

        if ($query !== []) {
            $url .= '?' . self::buildQuery($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new BadRequestException("could not initialise a request to {$url}");
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token],
            CURLOPT_USERAGENT => 'gameTracker-gt/1.0',
            // localhost development certificates are self-signed. This client
            // only ever talks to this host's own API.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new BadRequestException("could not reach {$url}: {$error}");
        }

        $decoded = json_decode((string)$body, true);

        if (!is_array($decoded)) {
            throw new BadRequestException(
                "unexpected response from {$url} (HTTP {$status})"
            );
        }

        if ($status === 404) {
            throw new NotFoundException($decoded['message'] ?? 'not found');
        }

        if ($status < 200 || $status >= 300) {
            throw new BadRequestException(
                $decoded['message'] ?? "request failed with HTTP {$status}"
            );
        }

        // v2 wraps success as { "data": ... }.
        return $decoded['data'] ?? $decoded;
    }

    /**
     * A flag must survive as a valueless key, because that is how both the CLI
     * and ArrayOptions read it. http_build_query would render `true` as "1",
     * which still works, but an explicit bare key keeps the wire format
     * matching what a hand-written request would look like.
     *
     * @param array<string, string|bool> $query
     */
    private static function buildQuery(array $query): string
    {
        $parts = [];

        foreach ($query as $key => $value) {
            if ($value === true) {
                $parts[] = rawurlencode((string)$key);
                continue;
            }
            if ($value === false || $value === null) {
                continue;
            }
            $parts[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
        }

        return implode('&', $parts);
    }
}
