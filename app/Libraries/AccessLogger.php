<?php

namespace App\Libraries;

use App\Models\AccessLogModel;
use CodeIgniter\HTTP\IncomingRequest;
use Throwable;

/**
 * Records a login / check-in / check-out with the device and network context it
 * came from.
 *
 * One entry point (`record()`) for all four call sites — `Auth::login()`,
 * `Auth::verify2fa()`, `Attendance::checkin()`, `Attendance::checkout()` — so
 * they cannot drift on what gets captured or how it is parsed.
 *
 * Two things worth knowing before trusting this data:
 *
 * 1. **The IP is only as good as `Config\App::$proxyIPs`.** Behind a reverse
 *    proxy with that unset, `getIPAddress()` returns the proxy's address. Same
 *    caveat as the attendance office-IP gate — see `attendance_helper.php`.
 *
 * 2. **Browser, OS and screen size are client-supplied.** The user agent is a
 *    header anyone can set; the screen size is posted by our own JavaScript.
 *    Both are useful for "which machine was this?" and are *never* safe as a
 *    security control.
 */
class AccessLogger
{
    /**
     * The POST/GET field our JavaScript puts `screen.width x screen.height` in.
     */
    public const SCREEN_FIELD = 'client_screen';

    /**
     * Writes one row. Never throws: an audit write must not be able to fail a
     * login or a check-in — losing a log line is bad, refusing someone entry to
     * the building because logging broke is worse. Failures go to the error log.
     */
    public function record(string $event, int $userId, ?IncomingRequest $request = null): void
    {
        if (! in_array($event, AccessLogModel::EVENTS, true) || $userId <= 0) {
            return;
        }

        try {
            $request ??= service('request');
            $agent = $request->getUserAgent();

            [$width, $height] = $this->screen($request);

            model(AccessLogModel::class)->insert([
                'user_id' => $userId,
                'event'   => $event,
                'ip'      => $request->getIPAddress(),
                // Bounded: the column is TEXT but a hostile client can send a
                // very long header, and nothing downstream needs more than this.
                'user_agent'    => mb_substr($agent->getAgentString(), 0, 500) ?: null,
                'browser'       => $this->label($agent->getBrowser(), $agent->getVersion()),
                'platform'      => $this->label($agent->getPlatform()),
                'device_type'   => $this->deviceType($agent),
                'screen_width'  => $width,
                'screen_height' => $height,
            ]);
        } catch (Throwable $e) {
            log_message('error', 'Could not write access log for user #{id} ({event}): {msg}', [
                'id'    => $userId,
                'event' => $event,
                'msg'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * `desktop` / `mobile` / `robot`.
     *
     * Tablets are deliberately not split out: CI4's mobile list is a fixed
     * string-match table that classifies most tablets as mobile anyway, so a
     * `tablet` value would be wrong often enough to mislead. The raw user agent
     * is stored if anyone needs to tell them apart later.
     */
    private function deviceType(object $agent): string
    {
        if ($agent->isRobot()) {
            return 'robot';
        }

        return $agent->isMobile() ? 'mobile' : 'desktop';
    }

    /**
     * `Chrome 121` — or null when the parser recognised nothing, so the column
     * says "unknown" instead of pretending with an empty string.
     */
    private function label(string $name, string $version = ''): ?string
    {
        $name = trim($name);

        if ($name === '') {
            return null;
        }

        $version = trim($version);

        return mb_substr($version === '' ? $name : $name . ' ' . $version, 0, 50);
    }

    /**
     * Parses the client-reported `1920x1080` into two ints.
     *
     * Anything malformed or out of range becomes null rather than a stored
     * absurdity — the field arrives from the browser, so it is input, and input
     * gets validated even when it is only ever displayed.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function screen(IncomingRequest $request): array
    {
        $raw = trim((string) ($request->getPost(self::SCREEN_FIELD) ?? $request->getGet(self::SCREEN_FIELD) ?? ''));

        if (preg_match('/^(\d{1,5})x(\d{1,5})$/', $raw, $matches) !== 1) {
            return [null, null];
        }

        $width  = (int) $matches[1];
        $height = (int) $matches[2];

        // SMALLINT UNSIGNED tops out at 65535; anything at either extreme is a
        // broken or forged value, not a screen.
        if ($width < 100 || $height < 100 || $width > 65535 || $height > 65535) {
            return [null, null];
        }

        return [$width, $height];
    }
}
