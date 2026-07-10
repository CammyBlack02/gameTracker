<?php
/**
 * SSRF-safe HTTP fetch helper.
 *
 * Every external URL fetch in the app goes through gt_safe_http_fetch().
 * It enforces:
 *   - HTTPS only
 *   - Host resolves entirely to public IPs (blocks private, loopback,
 *     link-local, reserved — including cloud metadata 169.254.169.254)
 *   - TLS verification stays on
 *   - Redirects are followed manually, revalidating each hop
 *
 * Throws GtSsrfException on any blocked host/IP.
 * Throws GtFetchException on network error or non-200 response.
 *
 * Returns ['data' => string, 'content_type' => string, 'http_code' => int].
 */

class GtSsrfException extends RuntimeException {}
class GtFetchException extends RuntimeException {}

/**
 * @param string $url  Full URL to fetch. Must be https://.
 * @param array  $opts Optional overrides:
 *                     'timeout'         => int seconds (default 30)
 *                     'connect_timeout' => int seconds (default 10)
 *                     'max_redirects'   => int (default 5)
 *                     'user_agent'      => string (default GameTracker/1.0)
 *                     'accept'          => string Accept header
 * @return array{data:string,content_type:string,http_code:int}
 */
function gt_safe_http_fetch(string $url, array $opts = []): array
{
    $timeout        = $opts['timeout']         ?? 30;
    $connectTimeout = $opts['connect_timeout'] ?? 10;
    $maxRedirects   = $opts['max_redirects']   ?? 5;
    $userAgent      = $opts['user_agent']      ?? 'GameTracker/1.0';
    $accept         = $opts['accept']          ?? '*/*';

    $currentUrl = $url;
    for ($hop = 0; $hop <= $maxRedirects; $hop++) {
        gt_ssrf_check_url($currentUrl);

        $ch = curl_init($currentUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => $userAgent,
            CURLOPT_HTTPHEADER     => ["Accept: $accept"],
            CURLOPT_HEADER         => false,
        ]);
        $data        = curl_exec($ch);
        $httpCode    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream');
        $redirectTo  = (string)(curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($curlError !== '') {
            throw new GtFetchException("curl error: $curlError");
        }

        if ($httpCode >= 300 && $httpCode < 400 && $redirectTo !== '') {
            if ($hop === $maxRedirects) {
                throw new GtFetchException("too many redirects (>$maxRedirects)");
            }
            $currentUrl = $redirectTo;
            continue;
        }

        if ($httpCode !== 200 || $data === false || $data === '') {
            throw new GtFetchException("HTTP $httpCode / empty body");
        }

        return [
            'data'         => $data,
            'content_type' => $contentType,
            'http_code'    => $httpCode,
        ];
    }
    throw new GtFetchException("redirect loop exceeded");
}

/**
 * Reject the URL if scheme != https, host is missing, or the host resolves
 * (or literally is) any private/loopback/link-local/reserved IP.
 */
function gt_ssrf_check_url(string $url): void
{
    $parts = @parse_url($url);
    if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
        throw new GtSsrfException("invalid URL");
    }
    if (strtolower($parts['scheme']) !== 'https') {
        throw new GtSsrfException("scheme not https: {$parts['scheme']}");
    }
    $host = trim($parts['host'], '[]');

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        gt_ssrf_check_ip($host);
        return;
    }

    $ips = gt_resolve_all($host);
    if (empty($ips)) {
        throw new GtFetchException("could not resolve host: $host");
    }
    foreach ($ips as $ip) {
        gt_ssrf_check_ip($ip);
    }
}

function gt_ssrf_check_ip(string $ip): void
{
    // Reject anything IANA-reserved / private / loopback. FILTER_FLAG_NO_RES_RANGE
    // already covers 0.0.0.0/8, 127/8, 169.254/16 (link-local + AWS metadata),
    // 192.0.0/24, 192.0.2/24, 198.18/15, 198.51.100/24, 203.0.113/24, 224/4, 240/4.
    // FILTER_FLAG_NO_PRIV_RANGE covers 10/8, 172.16/12, 192.168/16, fc00::/7,
    // fe80::/10, and IPv4-mapped IPv6.
    $ok = filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    );
    if ($ok === false) {
        throw new GtSsrfException("blocked IP: $ip");
    }
    if ($ip === '0.0.0.0' || $ip === '::' || $ip === '::1') {
        throw new GtSsrfException("blocked IP: $ip");
    }
}

function gt_resolve_all(string $host): array
{
    $ips = gethostbynamel($host) ?: [];
    $aaaa = @dns_get_record($host, DNS_AAAA);
    if (is_array($aaaa)) {
        foreach ($aaaa as $r) {
            if (!empty($r['ipv6'])) {
                $ips[] = $r['ipv6'];
            }
        }
    }
    return $ips;
}
