<?php

namespace App\Controllers\Api\V1;

use App\Models\ProjectModel;
use App\Models\TaskModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * `GET /api/v1/me` — the API key owner's profile + a couple of quick counts.
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
            'id'     => (int) $user->id,
            'name'   => $user->name,
            'email'  => $user->email,
            'counts' => [
                'projects' => count(model(ProjectModel::class)->forUser($this->userId())),
                'tasks'    => count(model(TaskModel::class)->forAssignee($this->userId())),
            ],
        ]);
    }
}
