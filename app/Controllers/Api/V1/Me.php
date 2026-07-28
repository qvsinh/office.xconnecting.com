<?php

namespace App\Controllers\Api\V1;

use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `GET /api/v1/me` — the API key owner's profile.
 *
 * The project/task counters were dropped with that module; the endpoint stays as
 * the "does my key work and who am I" probe, which is what clients use it for.
 */
class Me extends BaseApiController
{
    public function index(): ResponseInterface
    {
        $user = model(UserModel::class)->findUser($this->userId());

        if ($user === null) {
            return $this->fail('not_found', 'User not found.', 404);
        }

        return $this->ok([
            'id'    => (int) $user->id,
            'name'  => $user->name,
            'email' => $user->email,
        ]);
    }
}
