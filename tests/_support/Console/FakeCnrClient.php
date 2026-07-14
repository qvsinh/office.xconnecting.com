<?php

namespace Tests\Support\Console;

use App\Libraries\CnrClient;

/**
 * Test double for `App\Libraries\CnrClient`: records every `call()`
 * invocation (service id/env/command/params/meta) and returns a queued
 * canned response, without touching the network or `api_history`. Used to
 * isolate the console controllers' own logic (key resolution, ownership,
 * identity derivation) from the shared client's upstream/logging behavior,
 * which is covered separately by `CnrClientTest`.
 */
class FakeCnrClient extends CnrClient
{
    /** @var list<array{service_id: int, env: string, command: string, params: array<string, mixed>, meta: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, mixed> */
    private array $response = ['code' => '200', 'description' => 'Command completed successfully'];

    /**
     * @param array<string, mixed> $response
     */
    public function setResponse(array $response): void
    {
        $this->response = $response;
    }

    public function call(int $serviceId, string $env, string $command, array $params, array $meta = []): array
    {
        $this->calls[] = ['service_id' => $serviceId, 'env' => $env, 'command' => $command, 'params' => $params, 'meta' => $meta];

        return $this->response;
    }
}
