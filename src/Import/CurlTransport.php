<?php

namespace GameTracker\Import;

/**
 * The real transport. Deliberately dependency-free and dull.
 */
final class CurlTransport implements HttpTransport
{
    public function __construct(
        private readonly int $timeoutSeconds = 20,
    ) {
    }

    public function get(string $url): ?string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_USERAGENT => 'gameTracker-gt/1.0',
        ]);

        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }

        return (string)$body;
    }
}
