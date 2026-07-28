<?php

use CodeIgniter\I18n\Time;

/**
 * Attendance Helper
 *
 * The Vietnam-business-day and office-IP rules for check-in/check-out (see
 * kanban/processing/20260722-01-attendance-checkin-checkout.md).
 *
 * Timezone split, and why: every DATETIME in this app is stored UTC
 * (`Config\App::$appTimezone = 'UTC'`, and every model writes
 * `date('Y-m-d H:i:s')`). The *business* day — which calendar date a check-in
 * belongs to, and how times are displayed — is Vietnam time. Storing local time
 * instead would leave `checkin_at` and `created_at` seven hours apart on the
 * same row.
 *
 * Usage:
 *  helper('attendance');
 *  if (! office_ip_allowed()) { ... }
 */
if (! function_exists('attendance_tz')) {
    /**
     * The company's business timezone — used for "which day is it", for
     * display, and for the scheduled jobs' cutoffs.
     *
     * ponytail: single-region company, so a constant rather than a setting.
     * Make it a site_variable only if a second country is onboarded.
     */
    function attendance_tz(): string
    {
        return 'Asia/Ho_Chi_Minh';
    }
}

if (! function_exists('attendance_today')) {
    /**
     * Today's date on the Vietnam calendar (`Y-m-d`). At 17:30 UTC on the 21st
     * this is already the 22nd, which is exactly why `work_date` must not be
     * derived from the UTC clock.
     */
    function attendance_today(): string
    {
        return Time::now(attendance_tz())->toDateString();
    }
}

if (! function_exists('attendance_local')) {
    /**
     * Convert a stored UTC DATETIME to a Vietnam-time `Time` instance for
     * display or for date arithmetic. Returns null for a null/blank input so
     * callers can pass an optional column straight through.
     */
    function attendance_local(?string $utcDatetime): ?Time
    {
        if ($utcDatetime === null || trim($utcDatetime) === '') {
            return null;
        }

        return Time::parse($utcDatetime, 'UTC')->setTimezone(attendance_tz());
    }
}

if (! function_exists('attendance_worked_seconds')) {
    /**
     * Raw time between arrival and departure, or null while the day is still
     * open. Never negative — a corrupt or badly corrected pair reads as 0
     * rather than as negative payroll.
     *
     * Card 01b subtracts the schedule's lunch-break overlap from this; until
     * then it is the gross span.
     */
    function attendance_worked_seconds(?string $checkinAtUtc, ?string $checkoutAtUtc): ?int
    {
        if ($checkinAtUtc === null || $checkoutAtUtc === null) {
            return null;
        }

        $in  = strtotime($checkinAtUtc . ' UTC');
        $out = strtotime($checkoutAtUtc . ' UTC');

        if ($in === false || $out === false) {
            return null;
        }

        return max(0, $out - $in);
    }
}

if (! function_exists('attendance_format_duration')) {
    /**
     * `8h 05m` — the display format for worked time. Shared so the employee
     * card, the history table and the management list can't drift.
     */
    function attendance_format_duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return sprintf('%dh %02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}

if (! function_exists('attendance_local_time')) {
    /**
     * `08:30` in Vietnam time, or an em dash — the display form every attendance
     * table needs. Shared so five views don't each carry the same closure.
     */
    function attendance_local_time(?string $utcDatetime, string $fallback = '—'): string
    {
        $local = attendance_local($utcDatetime);

        return $local === null ? $fallback : $local->format('H:i');
    }
}

if (! function_exists('leave_request_label')) {
    /**
     * `Annual leave · afternoon` / `Late arrival (09:30)` — one description of a
     * leave request, used by both the employee list and the approval queue.
     */
    function leave_request_label(object $request): string
    {
        if (($request->type ?? '') === 'late') {
            $time = ($request->expected_time ?? null) === null ? '' : ' (' . substr((string) $request->expected_time, 0, 5) . ')';

            return 'Late arrival' . $time;
        }

        $label = ucfirst((string) ($request->leave_kind ?? '')) . ' leave';

        return ($request->day_portion ?? 'full') === 'full' ? $label : $label . ' · ' . $request->day_portion;
    }
}

if (! function_exists('leave_request_dates')) {
    /**
     * `2026-07-22` or `2026-07-20 → 2026-07-24`.
     */
    function leave_request_dates(object $request): string
    {
        $end = (string) ($request->end_date ?? $request->start_date);

        return $end === (string) $request->start_date ? (string) $request->start_date : $request->start_date . ' → ' . $end;
    }
}

if (! function_exists('attendance_ip_enforced')) {
    /**
     * Whether check-in/out is restricted to the office network at all.
     */
    function attendance_ip_enforced(): bool
    {
        helper('setting');

        return get_site_variable('attendance_ip_enforce', '0') === '1';
    }
}

if (! function_exists('office_ip_allowed')) {
    /**
     * Whether `$ip` (default: the current request's address) may record
     * attendance.
     *
     * Enforcement off -> always true. Enforcement on but no allowlist
     * configured -> also true: there is nothing to match against, and locking
     * the whole company out of check-in is worse than not enforcing yet. The
     * management screen warns about both states.
     *
     * NOTE: this is only as trustworthy as `Config\App::$proxyIPs`. Behind a
     * reverse proxy or CDN with `$proxyIPs = []`, `getIPAddress()` returns the
     * proxy's address, not the employee's — see the card's §0.
     */
    function office_ip_allowed(?string $ip = null): bool
    {
        if (! attendance_ip_enforced()) {
            return true;
        }

        helper('setting');
        $list = trim((string) get_site_variable('attendance_office_ips', ''));

        if ($list === '') {
            return true;
        }

        $ip ??= service('request')->getIPAddress();

        foreach (array_filter(array_map('trim', explode(',', $list))) as $rule) {
            if (attendance_ip_matches($rule, $ip)) {
                return true;
            }
        }

        return false;
    }
}

if (! function_exists('attendance_ip_matches')) {
    /**
     * Match an IP against one allowlist rule: a plain address (equality) or
     * CIDR (`prefix/bits`). Handles IPv4 and IPv6 alike by comparing the
     * packed `inet_pton` bytes, so `10.0.0.1` and `010.0.0.1` compare equal and
     * a v4 rule never accidentally matches a v6 address.
     *
     * A malformed rule returns false rather than throwing — a typo in a setting
     * must not 500 the check-in button.
     *
     * ponytail: byte-mask compare, no library.
     */
    function attendance_ip_matches(string $rule, string $ip): bool
    {
        $packedIp = @inet_pton($ip);

        if ($packedIp === false) {
            return false;
        }

        if (! str_contains($rule, '/')) {
            $packedRule = @inet_pton($rule);

            return $packedRule !== false && hash_equals($packedRule, $packedIp);
        }

        [$prefix, $bits] = explode('/', $rule, 2);

        $packedPrefix = @inet_pton(trim($prefix));

        if ($packedPrefix === false || ! is_numeric($bits)) {
            return false;
        }

        // A v4 rule cannot cover a v6 address (or vice versa): different widths.
        if (strlen($packedPrefix) !== strlen($packedIp)) {
            return false;
        }

        $bits = (int) $bits;

        if ($bits < 0 || $bits > strlen($packedIp) * 8) {
            return false;
        }

        $wholeBytes = intdiv($bits, 8);
        $extraBits  = $bits % 8;

        if ($wholeBytes > 0 && ! hash_equals(substr($packedPrefix, 0, $wholeBytes), substr($packedIp, 0, $wholeBytes))) {
            return false;
        }

        if ($extraBits === 0) {
            return true;
        }

        $mask = ~((1 << (8 - $extraBits)) - 1) & 0xFF;

        return (ord($packedIp[$wholeBytes]) & $mask) === (ord($packedPrefix[$wholeBytes]) & $mask);
    }
}

if (! function_exists('attendance_locked_through')) {
    /**
     * The payroll close marker (`YYYY-MM`), or '' when nothing is locked.
     */
    function attendance_locked_through(): string
    {
        helper('setting');

        return trim((string) get_site_variable('attendance_locked_through', ''));
    }
}

if (! function_exists('attendance_period_locked')) {
    /**
     * Whether `$workDate` (`Y-m-d`) falls in a month already closed for
     * payroll. Corrections there are refused so an exported/paid month cannot
     * be rewritten after the fact.
     */
    function attendance_period_locked(string $workDate): bool
    {
        $lockedThrough = attendance_locked_through();

        if ($lockedThrough === '') {
            return false;
        }

        return substr($workDate, 0, 7) <= $lockedThrough;
    }
}
