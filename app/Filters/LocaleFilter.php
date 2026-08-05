<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;

/**
 * Decides which language this request renders in, before any controller runs.
 *
 * `Services::language()` takes its locale from the request, so this has to happen
 * in a `before` filter — by the time a view calls `lang()` the choice is already
 * made.
 *
 * Order, most specific first:
 *
 *   1. `locale` in the session — an explicit choice from the header switcher, and
 *      the only one that should win immediately.
 *   2. The signed-in user's `users.language`, so the choice follows them to
 *      another browser. (Until now that column was stored and never read.)
 *   3. `Config\App::$defaultLocale`.
 *
 * Anything not in `$supportedLocales` is ignored rather than trusted: the value can
 * come from a session an attacker influenced, and it is interpolated into a
 * filesystem path by CI4's file loader.
 */
class LocaleFilter implements FilterInterface
{
    public const SESSION_KEY = 'locale';

    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        // Only an HTTP request carries a locale; a CLI request (spark, the
        // scheduled-task runner) has nothing to set and no view to render.
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $config    = config(App::class);
        $supported = $config->supportedLocales;

        $locale = null;

        foreach ([session(self::SESSION_KEY), session('userWorking')->language ?? null] as $candidate) {
            $candidate = is_string($candidate) ? trim($candidate) : '';

            if ($candidate !== '' && in_array($candidate, $supported, true)) {
                $locale = $candidate;
                break;
            }
        }

        $request->setLocale($locale ?? $config->defaultLocale);

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
