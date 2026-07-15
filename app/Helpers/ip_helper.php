<?php

use CodeIgniter\HTTP\RequestInterface;

if (! function_exists('parse_ip_rules')) {
    /**
     * Split a per-key `allowed_ips` blob (newline and/or comma separated)
     * into a deduped list of trimmed, non-blank rule strings.
     *
     * @return list<string>
     */
    function parse_ip_rules(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,]+/', $raw) ?: [];
        $rules = array_filter(array_map('trim', $parts), static fn (string $rule): bool => $rule !== '');

        return array_values(array_unique($rules));
    }
}

if (! function_exists('ip_rule_is_valid')) {
    /**
     * True if `$rule` is a valid single IP (v4/v6) or a valid CIDR block
     * (`ip/prefix`, prefix 0-32 for v4, 0-128 for v6).
     */
    function ip_rule_is_valid(string $rule): bool
    {
        if (! str_contains($rule, '/')) {
            return filter_var($rule, FILTER_VALIDATE_IP) !== false;
        }

        [$ip, $prefix] = explode('/', $rule, 2);

        if (! ctype_digit($prefix)) {
            return false;
        }

        $packed = inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        $maxPrefix = strlen($packed) === 4 ? 32 : 128;

        return (int) $prefix >= 0 && (int) $prefix <= $maxPrefix;
    }
}

if (! function_exists('ip_matches_allowlist')) {
    /**
     * True if `$clientIp` is covered by any rule in `$rules` (exact IP match
     * or CIDR membership). Comparisons run on `inet_pton()`-packed bytes so
     * IPv4/IPv6 and equivalent address forms (e.g. `::1` vs the expanded
     * form) compare correctly.
     *
     * ponytail: fails closed — an empty/unparseable `$clientIp` always
     * returns false rather than risking a false "allowed" on a malformed
     * client address. Callers only invoke this when `$rules` is non-empty
     * (blank `allowed_ips` means "no restriction" and is handled upstream).
     */
    function ip_matches_allowlist(string $clientIp, array $rules): bool
    {
        $clientPacked = $clientIp === '' ? false : inet_pton($clientIp);

        if ($clientPacked === false) {
            return false;
        }

        foreach ($rules as $rule) {
            if (! str_contains($rule, '/')) {
                if (inet_pton($rule) === $clientPacked) {
                    return true;
                }

                continue;
            }

            [$ruleIp, $prefix] = explode('/', $rule, 2);
            $rulePacked        = inet_pton($ruleIp);

            if ($rulePacked === false || strlen($rulePacked) !== strlen($clientPacked)) {
                continue;
            }

            if (packed_ip_matches_prefix($clientPacked, $rulePacked, (int) $prefix)) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('packed_ip_matches_prefix')) {
    /**
     * Compare the first `$prefixBits` bits of two `inet_pton()`-packed
     * addresses (same length, guaranteed by the caller), masking the final
     * partial byte so the comparison is byte-safe for both IPv4 (4 bytes)
     * and IPv6 (16 bytes).
     */
    function packed_ip_matches_prefix(string $a, string $b, int $prefixBits): bool
    {
        // Clamp defensively: rules are validated by ip_rule_is_valid() before
        // storage, but a prefix wider than the address itself must never
        // read past the packed bytes.
        $prefixBits    = max(0, min($prefixBits, strlen($a) * 8));
        $fullBytes     = intdiv($prefixBits, 8);
        $remainderBits = $prefixBits % 8;

        if ($fullBytes > 0 && substr($a, 0, $fullBytes) !== substr($b, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = (~0 << (8 - $remainderBits)) & 0xFF;

        return (ord($a[$fullBytes]) & $mask) === (ord($b[$fullBytes]) & $mask);
    }
}

if (! function_exists('cloudflare_ip_ranges')) {
    /**
     * Cloudflare's published edge IP ranges (CIDR strings), used by
     * `resolve_client_ip()` to decide whether `CF-Connecting-IP` can be
     * trusted for a given request.
     *
     * Source: https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6
     * Snapshot taken 2026-07-15 — refresh this list if Cloudflare changes its
     * edge ranges (they do so rarely; there is no live lookup here).
     *
     * @return list<string>
     */
    function cloudflare_ip_ranges(): array
    {
        return [
            // IPv4
            '173.245.48.0/20',
            '103.21.244.0/22',
            '103.22.200.0/22',
            '103.31.4.0/22',
            '141.101.64.0/18',
            '108.162.192.0/18',
            '190.93.240.0/20',
            '188.114.96.0/20',
            '197.234.240.0/22',
            '198.41.128.0/17',
            '162.158.0.0/15',
            '104.16.0.0/13',
            '104.24.0.0/14',
            '172.64.0.0/13',
            '131.0.72.0/22',
            // IPv6
            '2400:cb00::/32',
            '2606:4700::/32',
            '2803:f800::/32',
            '2405:b500::/32',
            '2405:8100::/32',
            '2a06:98c0::/29',
            '2c0f:f248::/32',
        ];
    }
}

if (! function_exists('resolve_client_ip')) {
    /**
     * Resolve the real client IP across both supported deployment topologies:
     * (1) direct — the domain's A record points straight at this server, and
     * (2) behind Cloudflare, which rewrites `REMOTE_ADDR` to its own edge IP
     * and forwards the true client IP in `CF-Connecting-IP`.
     *
     * Anti-spoof rationale: `CF-Connecting-IP` is a plain client-settable
     * header. A request that reaches the origin directly (bypassing
     * Cloudflare) can set it to anything, so it is ONLY trusted when
     * `REMOTE_ADDR` itself is a genuine Cloudflare edge IP — that address
     * comes from the TCP connection, which an external attacker cannot forge.
     * Any other topology (no proxy, or a different reverse proxy) falls back
     * to `IncomingRequest::getIPAddress()`, which is `REMOTE_ADDR` unless
     * `Config\App::$proxyIPs` names a trusted proxy hop.
     */
    function resolve_client_ip(RequestInterface $request): string
    {
        $remote = (string) $request->getServer('REMOTE_ADDR');

        if ($remote !== '' && ip_matches_allowlist($remote, cloudflare_ip_ranges())) {
            $cfIp = $request->getHeaderLine('CF-Connecting-IP');

            if (filter_var($cfIp, FILTER_VALIDATE_IP) !== false) {
                return $cfIp;
            }
        }

        return $request->getIPAddress();
    }
}

if (! function_exists('normalize_ip_rules')) {
    /**
     * Parse + validate a raw `allowed_ips` textarea blob (newline/comma
     * separated IPs and/or CIDRs) into the normalized, newline-joined form
     * stored on a key row. Blank input means "no restriction" (allow all).
     *
     * Single source of truth for the admin + client write paths — never
     * duplicate this validation per-controller, or the two entry points could
     * drift and one would accept what the other rejects.
     *
     * @return array{0: string, 1: ?string} [normalized value, error message or null]
     */
    function normalize_ip_rules(string $raw): array
    {
        $rules = parse_ip_rules($raw);

        foreach ($rules as $rule) {
            if (! ip_rule_is_valid($rule)) {
                return ['', "Invalid IP or CIDR: {$rule}"];
            }
        }

        return [implode("\n", $rules), null];
    }
}
