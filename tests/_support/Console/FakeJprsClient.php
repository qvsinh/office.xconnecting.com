<?php

namespace Tests\Support\Console;

use App\Libraries\JprsClient;

/**
 * Test double for `App\Libraries\JprsClient`: records every `call()`
 * invocation and returns a queued canned raw response body. `parseJprsResponse()`
 * is inherited from the real client (pure text parsing, no network/log side
 * effects), so the console's parse path is exercised for real.
 */
class FakeJprsClient extends JprsClient
{
    /** @var list<array{service_id: int, env: string, command: string, params: array<string, mixed>, meta: array<string, mixed>}> */
    public array $calls = [];

    private false|string $response = "Result:Completed\n200 Command completed successfully";

    public function setResponse(false|string $response): void
    {
        $this->response = $response;
    }

    public function call(int $serviceId, string $env, string $command, array $params, array $meta = []): false|string
    {
        $this->calls[] = ['service_id' => $serviceId, 'env' => $env, 'command' => $command, 'params' => $params, 'meta' => $meta];

        return $this->response;
    }
}
