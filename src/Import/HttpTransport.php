<?php

namespace GameTracker\Import;

/**
 * The seam that keeps live network calls out of CI.
 *
 * SteamSource depends on this rather than calling curl directly, so a test can
 * feed it a recorded payload. Without it the Steam path would be untestable —
 * needing a real API key, real latency, and a result that changes underneath
 * the assertions.
 */
interface HttpTransport
{
    /** Returns the response body, or null on any failure. */
    public function get(string $url): ?string;
}
