<?php

namespace Tests\Support\Api;

/**
 * Minimal duck-typed stand-in for `CodeIgniter\HTTP\ResponseInterface` as
 * returned by `service('curlrequest')->get()/->post()` — the proxy
 * controllers only ever call `->getBody()` on the result.
 */
class FakeCurlResponse
{
    public function __construct(private readonly string $body)
    {
    }

    public function getBody(): string
    {
        return $this->body;
    }
}
