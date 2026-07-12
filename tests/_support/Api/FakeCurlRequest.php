<?php

namespace Tests\Support\Api;

use RuntimeException;
use Throwable;

/**
 * Duck-typed stand-in for CodeIgniter's `curlrequest` service. Queue canned
 * RRP/JPRS response bodies (or a Throwable, to simulate an upstream
 * exception) and bind via `Services::injectMock('curlrequest', $fake)` so
 * `CnrApi`/`JprsApi` never hit the real network in tests.
 */
class FakeCurlRequest
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<FakeCurlResponse|Throwable> */
    private array $queue = [];

    public function queue(FakeCurlResponse|Throwable $response): static
    {
        $this->queue[] = $response;

        return $this;
    }

    public function get(string $url, array $options = []): FakeCurlResponse
    {
        $this->calls[] = ['method' => 'GET', 'url' => $url, 'options' => $options];

        return $this->next();
    }

    public function post(string $url, array $options = []): FakeCurlResponse
    {
        $this->calls[] = ['method' => 'POST', 'url' => $url, 'options' => $options];

        return $this->next();
    }

    private function next(): FakeCurlResponse
    {
        $next = array_shift($this->queue);

        if ($next === null) {
            throw new RuntimeException('FakeCurlRequest: no queued response left.');
        }

        if ($next instanceof Throwable) {
            throw $next;
        }

        return $next;
    }
}
