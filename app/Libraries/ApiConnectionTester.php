<?php

namespace App\Libraries;

use Throwable;

/**
 * Loopback self-test: proves an API access key actually authenticates
 * against the real public proxy endpoint (base URL + `ApiFilter` Bearer
 * decode + env-segment match + routing), end-to-end.
 *
 * The probe command is always `History` — it's handled locally by the
 * proxy (returns the key's own `api_history` log) and never reaches the
 * upstream registrar, so a self-test never has a billing/provisioning
 * side effect. A bad/disabled/env-mismatched key simply surfaces as the
 * real 401 `ApiFilter` would return to any other caller.
 *
 * The access-key token is only ever used as the `Authorization` header of
 * the loopback request here — it must never appear in the returned array
 * (`endpoint`/`error` are built without it, and any error message is
 * scrubbed of it as a defense-in-depth backstop).
 */
class ApiConnectionTester
{
    /**
     * @param ?object $client Duck-typed `curlrequest` service (real
     *                        `CodeIgniter\HTTP\CURLRequest` in production);
     *                        injected here so tests can pass a fake without
     *                        touching the `curlrequest` service locator.
     */
    public function __construct(private readonly ?object $client = null)
    {
    }

    /**
     * `env` is `'LIVE'`/`'TESTING'` (as stored on the key row); the URL
     * segment is always lower-case.
     */
    public function buildEndpoint(string $provider, string $env): string
    {
        return rtrim(config('App')->baseURL, '/') . '/api/' . $provider . '/' . strtolower($env) . '/';
    }

    /**
     * @return array{base_url: string, endpoint: string, http_status: int, ok: bool,
     *     latency_ms: int, response: mixed, error: ?string}
     */
    public function probe(object $key, string $provider, string $env): array
    {
        $endpoint = $this->buildEndpoint($provider, $env);
        $baseUrl  = rtrim(config('App')->baseURL, '/');
        $client   = $this->client ?? service('curlrequest');
        $started  = microtime(true);

        try {
            $response = $client->get($endpoint, [
                'query'       => ['command' => 'History', 'limit' => 1],
                'headers'     => ['Authorization' => 'Bearer ' . $key->access_key],
                'timeout'     => 10,
                'http_errors' => false, // never throw on 4xx/5xx — a rejected key is a normal result here
            ]);

            $status  = $response->getStatusCode();
            $decoded = $this->decodeBody((string) $response->getBody());

            return [
                'base_url'    => $baseUrl,
                'endpoint'    => $endpoint,
                'http_status' => $status,
                'ok'          => $status >= 200 && $status < 300 && is_array($decoded) && ($decoded['status'] ?? null) === 'success',
                'latency_ms'  => $this->elapsedMs($started),
                'response'    => $decoded,
                'error'       => null,
            ];
        } catch (Throwable $e) {
            return [
                'base_url'    => $baseUrl,
                'endpoint'    => $endpoint,
                'http_status' => 0,
                'ok'          => false,
                'latency_ms'  => $this->elapsedMs($started),
                'response'    => null,
                'error'       => $this->redact($e->getMessage(), (string) $key->access_key),
            ];
        }
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * Defense-in-depth: the token is never expected inside a transport
     * exception message, but strip it anyway rather than trust that.
     */
    private function redact(string $message, string $token): string
    {
        return $token === '' ? $message : str_replace($token, '***', $message);
    }

    /**
     * JSON-decode the response body, truncated to ~4000 chars. Falls back
     * to the (truncated) raw string when the body isn't valid JSON.
     */
    private function decodeBody(string $raw): mixed
    {
        $truncated = mb_strlen($raw) > 4000 ? mb_substr($raw, 0, 4000) . '…' : $raw;
        $decoded   = json_decode($truncated, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $truncated;
    }
}
