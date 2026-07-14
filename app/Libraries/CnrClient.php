<?php

namespace App\Libraries;

use App\Models\ApiHistoryModel;
use Throwable;

/**
 * Shared RRP (CNR/RRPProxy) upstream client: builds the query, calls the
 * registrar over `service('curlrequest')`, parses the `key=value` response,
 * and records `api_history` via `ApiHistoryModel::record()`. This is the
 * single place an upstream CNR call happens — used by the Bearer proxy
 * (`Api\CnrApi`) today and, from Phase 2, the web developer console — so
 * neither caller duplicates the request/parse/log logic.
 */
class CnrClient
{
    /**
     * @param array<string, mixed>                     $params ponytail: 'metadata' key, if present, is
     *                                                         business metadata stored on the history row —
     *                                                         not call context (see `$meta`)
     * @param array{user_id?: ?int, enable_log?: bool} $meta   caller identity/log-gate context
     *
     * @return array<string, mixed>
     */
    public function call(int $serviceId, string $env, string $command, array $params, array $meta = []): array
    {
        helper('service_config');

        $metadata = $params['metadata'] ?? null;
        unset($params['metadata']);

        $config = get_cnr_service_config($serviceId);

        if (empty($config['name']) || empty($config['password']) || empty($config['url'])) {
            return [
                'code'        => 500,
                'description' => 'Configuration for service cnr.' . $serviceId . ' not found.',
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

            model(ApiHistoryModel::class)->record([
                'user_id'      => $meta['user_id'] ?? null,
                'env'          => $env,
                'service_name' => 'cnr',
                'service_id'   => $serviceId,
                'url'          => $config['url'],
                'status'       => (isset($responseData['code']) && str_starts_with((string) $responseData['code'], '2')) ? 1 : 0,
                'command'      => $command,
                'domain'       => $domain,
                'params'       => $params,
                'response'     => $responseData,
                'metadata'     => $metadata,
            ], (bool) ($meta['enable_log'] ?? false));

            return $responseData;
        } catch (Throwable $e) {
            model(ApiHistoryModel::class)->record([
                'user_id'      => $meta['user_id'] ?? null,
                'env'          => $env,
                'service_name' => 'cnr',
                'service_id'   => $serviceId,
                'url'          => $config['url'],
                'status'       => 0,
                'command'      => $command,
                'domain'       => $domain,
                'params'       => $params,
                'response'     => ['error' => $e->getMessage()],
                'metadata'     => $metadata,
            ], (bool) ($meta['enable_log'] ?? false));

            return ['code' => 500, 'description' => $e->getMessage()];
        }
    }

    /**
     * Parse RRP's `key=value` line protocol, folding `property[name][idx]=value`
     * lines into a `property.{name}` indexed array.
     *
     * @return array<string, mixed>
     */
    public function parseRrpResponse(string $responseBody): array
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
