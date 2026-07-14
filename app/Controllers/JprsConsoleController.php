<?php

namespace App\Controllers;

/**
 * JPRS (.jp) console: identical upstream call/parse/log as the Bearer proxy
 * (`Api\JprsApi::proxy`), via the shared `App\Libraries\JprsClient`
 * (`service('jprsClient')`) — never reimplemented here.
 */
abstract class JprsConsoleController extends ConsoleController
{
    protected function provider(): string
    {
        return 'jprs';
    }

    protected function callUpstream(object $key, string $env, string $command, array $params, int $userId): array
    {
        $client   = service('jprsClient');
        $response = $client->call(
            (int) $key->service_id,
            $env,
            $command,
            $params,
            ['user_id' => $userId, 'enable_log' => (int) $key->enable_log === 1],
        );

        if ($response === false) {
            return [
                'success'     => false,
                'data'        => null,
                'message'     => 'Configuration for service jprs.' . $key->service_id . ' not found.',
                'http_status' => 400,
            ];
        }

        ['code' => $code, 'description' => $description, 'data' => $data, 'raw_response' => $raw] = $client->parseJprsResponse($response);

        return [
            'success' => $code !== null && str_starts_with($code, '2'),
            'data'    => ['code' => $code, 'description' => $description, 'data' => $data, 'raw_response' => $raw],
            'message' => $description ?: 'An error occurred',
        ];
    }
}
