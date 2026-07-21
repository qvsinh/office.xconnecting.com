<?php

namespace App\Controllers;

/**
 * In-app documentation: hub usage guide + REST API connection guide for
 * wiring an AI agent to a project via an API key (see
 * `kanban/processing/20260721-09-docs.md`). Static content, no models — the
 * only dynamic value passed to a view is the API base URL, kept in sync
 * with the real `api/v1` route group so copy-paste examples stay accurate.
 */
class Docs extends BaseController
{
    public function index(): string
    {
        return view('docs/index');
    }

    public function gettingStarted(): string
    {
        return view('docs/getting_started');
    }

    public function api(): string
    {
        return view('docs/api', [
            'apiBaseUrl' => rtrim(base_url('api/v1'), '/'),
        ]);
    }
}
