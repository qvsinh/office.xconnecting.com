<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * RRP (CNR/RRPProxy) registrar proxy — forwards `command` + params to the
 * upstream RRP HTTP API and normalizes the response into the standard
 * success/error envelope. See ../../../../proxy.services.vn/app/Controllers/Api/CnrApi.php
 * for the legacy contract this ports. The upstream call/parse/log logic
 * itself lives in the shared `App\Libraries\CnrClient` (`service('cnrClient')`)
 * so it isn't duplicated by the web developer console.
 */
class CnrApi extends ApiController
{
    /**
     * Domains for which certain commands are blocked outright.
     */
    private const DOMAIN_COMMAND_RESTRICTIONS = [
        'dnp.sg' => ['ModifyDomain'],
    ];

    public function proxy(): ResponseInterface
    {
        $command = $this->request->getVar('command');

        if (empty($command)) {
            return $this->response->setJSON([
                'status'         => 'error',
                'api_service_id' => 'cnr.' . $this->service_id,
                'message'        => 'Command parameter is missing.',
            ])->setStatusCode(400);
        }

        $command = (string) $command;

        if (strcasecmp($command, 'History') === 0) {
            return $this->fetchHistory('cnr');
        }

        $params = $this->request->getVar() ?: [];
        $domain = $params['domain'] ?? $params['DomainName'] ?? null;

        if ($domain !== null && in_array($command, self::DOMAIN_COMMAND_RESTRICTIONS[$domain] ?? [], true)) {
            return $this->response->setJSON([
                'status'         => 'error',
                'api_service_id' => 'cnr.' . $this->service_id,
                'message'        => "Command '{$command}' is not allowed for domain '{$domain}'.",
            ])->setStatusCode(403);
        }

        $apiResponse = service('cnrClient')->call(
            $this->service_id,
            $this->user->env ?? 'TESTING',
            $command,
            $params,
            ['user_id' => $this->user->id ?? null, 'enable_log' => (int) $this->key_enable_log === 1],
        );

        if (isset($apiResponse['code']) && str_starts_with((string) $apiResponse['code'], '2')) {
            return $this->response->setJSON([
                'status'         => 'success',
                'api_service_id' => 'cnr.' . $this->service_id,
                'data'           => $apiResponse,
            ])->setStatusCode(200);
        }

        return $this->response->setJSON([
            'status'         => 'error',
            'api_service_id' => 'cnr.' . $this->service_id,
            'data'           => $apiResponse,
            'message'        => $apiResponse['description'] ?? 'An error occurred',
        ])->setStatusCode(200);
    }
}
