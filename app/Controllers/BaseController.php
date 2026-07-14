<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 *
 * Extend this class in any new controllers:
 * ```
 *     class Home extends BaseController
 * ```
 *
 * For security, be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */

    // protected $session;

    /**
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Load here all helpers you want to be available in your controllers that extend BaseController.
        // Caution: Do not put the this below the parent::initController() call below.
        // $this->helpers = ['form', 'url'];

        // Caution: Do not edit this line.
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.
        // $this->session = service('session');
    }

    /**
     * Shared JSON success envelope (`status`/`message`/`csrf_hash`) for
     * fetch()-driven mutation endpoints, matching Auth::login()'s pattern.
     *
     * @param array<string, mixed> $extra
     */
    protected function jsonSuccess(string $message, array $extra = []): ResponseInterface
    {
        return $this->response->setJSON(array_merge([
            'status'    => 'success',
            'message'   => $message,
            'csrf_hash' => csrf_hash(),
        ], $extra));
    }

    /**
     * Shared JSON failure envelope. Accepts either a plain message or a
     * CI4 validator error array (flattened into one string).
     *
     * @param array<string, string>|string $message
     */
    protected function jsonFailed(array|string $message, int $status = 400): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'status'    => 'failed',
            'message'   => is_array($message) ? implode(' ', $message) : $message,
            'csrf_hash' => csrf_hash(),
        ]);
    }

    /**
     * Resolve a paginated list's per-page limit from `?limit=`, falling back
     * to the `app.rpp_default` env knob (then 25). Always clamped to 100 so a
     * single request can never pull an unbounded result set (OOM/DoS).
     *
     * Shared here because every list controller needs the identical rule —
     * keeping one copy prevents the clamp from silently drifting per caller.
     */
    protected function resolveLimit(): int
    {
        $limitGet = (int) $this->request->getGet('limit');

        if ($limitGet > 0) {
            return min($limitGet, 100);
        }

        $default = (int) env('app.rpp_default');

        return $default > 0 ? min($default, 100) : 25;
    }
}
