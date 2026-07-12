<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

/**
 * RRP (CNR/RRPProxy) registrar proxy — forwards `command` + params to the
 * upstream RRP HTTP API and normalizes the response into the standard
 * success/error envelope. See ../../../../proxy.services.vn/app/Controllers/Api/CnrApi.php
 * for the legacy contract this ports.
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

        $apiResponse = $this->sendRequest($command, $params);

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

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function sendRequest(string $command, array $params): array
    {
        $metadata = $params['metadata'] ?? null;
        unset($params['metadata']);

        $config = get_cnr_service_config($this->service_id);

        if (empty($config['name']) || empty($config['password']) || empty($config['url'])) {
            return [
                'code'        => 500,
                'description' => 'Configuration for service cnr.' . $this->service_id . ' not found.',
            ];
        }

        $queryParams = [
            's_login' => $config['name'],
            's_pw'    => $config['password'],
            'command' => $command,
        ] + $params;

        $domain = $params['domain'] ?? $params['DomainName'] ?? null;

        try {
            $response     = service('curlrequest')->get($config['url'], ['query' => $queryParams]);
            $responseData = $this->parseRrpResponse((string) $response->getBody());

            $this->logApiCall([
                'env'          => $this->user->env ?? 'TESTING',
                'service_name' => 'cnr',
                'service_id'   => $this->service_id,
                'url'          => $config['url'],
                'status'       => (isset($responseData['code']) && str_starts_with((string) $responseData['code'], '2')) ? 1 : 0,
                'command'      => $command,
                'domain'       => $domain,
                'params'       => $params,
                'response'     => $responseData,
                'metadata'     => $metadata,
            ]);

            return $responseData;
        } catch (Throwable $e) {
            $this->logApiCall([
                'env'          => $this->user->env ?? 'TESTING',
                'service_name' => 'cnr',
                'service_id'   => $this->service_id,
                'url'          => $config['url'],
                'status'       => 0,
                'command'      => $command,
                'domain'       => $domain,
                'params'       => $params,
                'response'     => ['error' => $e->getMessage()],
                'metadata'     => $metadata,
            ]);

            return ['code' => 500, 'description' => $e->getMessage()];
        }
    }

    /**
     * Parse RRP's `key=value` line protocol, folding `property[name][idx]=value`
     * lines into a `property.{name}` indexed array.
     *
     * @return array<string, mixed>
     */
    private function parseRrpResponse(string $responseBody): array
    {
        $lines  = explode("\n", trim($responseBody));
        $result = [];

        foreach ($lines as $line) {
            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key           = trim($key);
            $value         = trim($value);

            if (preg_match('/property\[([^\]]+)\]\[(\d+)\]/', $key, $matches)) {
                $result['property'][$matches[1]][(int) $matches[2]] = $value;
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
