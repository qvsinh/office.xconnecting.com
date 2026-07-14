<?php

namespace App\Controllers;

/**
 * Static, in-app API documentation for the CNR/JPRS proxy (`/api-docs`),
 * shared by admin + client (both extend `layouts/app` and sit behind
 * `authFilter`) — no `Admin\`/`Client\` split, the content is identical.
 *
 * Transport-only: no model, no DB, no per-user data. `list_api_services()`
 * only ever surfaces id/provider/label (see service_config_helper) — never
 * an access_key or secret.
 */
class ApiDocs extends BaseController
{
    public function index(): string
    {
        helper('service_config');

        return view('api_docs/index', [
            'proxyBaseUrl' => rtrim(base_url(), '/'),
            'services'     => list_api_services(),
        ]);
    }
}
