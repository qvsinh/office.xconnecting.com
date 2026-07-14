<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * JPRS (.jp) registrar proxy over client-cert HTTPS. Accepts GET and POST —
 * `getVar()` merges query + body so callers can send params either way. Ports
 * ../../../../proxy.services.vn/app/Controllers/Api/JprsApi.php's payload/
 * response contract. The upstream call/parse/log logic itself lives in the
 * shared `App\Libraries\JprsClient` (`service('jprsClient')`) so it isn't
 * duplicated by the web developer console.
 */
class JprsApi extends ApiController
{
    public function proxy(): ResponseInterface
    {
        $command = $this->request->getVar('Command');

        if (empty($command)) {
            return $this->response->setJSON([
                'status'         => 'error',
                'api_service_id' => 'jprs.' . $this->service_id,
                'message'        => 'Command parameter is missing.',
            ])->setStatusCode(400);
        }

        $command = (string) $command;

        if (strcasecmp($command, 'History') === 0) {
            return $this->fetchHistory('jprs');
        }

        $params = $this->request->getVar() ?: [];

        $apiResponse = service('jprsClient')->call(
            $this->service_id,
            $this->user->env ?? 'TESTING',
            $command,
            $params,
            ['user_id' => $this->user->id ?? null, 'enable_log' => (int) $this->key_enable_log === 1],
        );

        if ($apiResponse === false) {
            return $this->response->setJSON([
                'status'         => 'error',
                'api_service_id' => 'jprs.' . $this->service_id,
                'message'        => 'Configuration for service jprs.' . $this->service_id . ' not found.',
            ])->setStatusCode(400);
        }

        ['code' => $code, 'description' => $description, 'data' => $data, 'raw_response' => $raw] = service('jprsClient')->parseJprsResponse($apiResponse);

        if ($code !== null && str_starts_with($code, '2')) {
            return $this->response->setJSON([
                'status'         => 'success',
                'api_service_id' => 'jprs.' . $this->service_id,
                'data'           => [
                    'code'         => $code,
                    'description'  => $description,
                    'data'         => $data,
                    'raw_response' => $raw,
                ],
            ])->setStatusCode(200);
        }

        return $this->response->setJSON([
            'status'         => 'error',
            'api_service_id' => 'jprs.' . $this->service_id,
            'data'           => [
                'code'         => $code,
                'description'  => $description,
                'data'         => $data,
                'raw_response' => $raw,
            ],
            'message' => $description ?: 'An error occurred',
        ])->setStatusCode(200);
    }
}
