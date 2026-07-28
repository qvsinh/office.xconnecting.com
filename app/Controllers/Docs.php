<?php

namespace App\Controllers;

/**
 * In-app documentation: how attendance works, for employees and managers.
 *
 * The REST API page went with the projects/tasks module — the API is down to
 * `GET /api/v1/me`, which needs no page of its own. Static content, no models.
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
}
