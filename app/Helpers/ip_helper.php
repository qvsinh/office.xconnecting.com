<?php

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
