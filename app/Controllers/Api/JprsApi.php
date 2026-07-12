<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * JPRS (.jp) registrar proxy over client-cert HTTPS. Accepts GET and POST —
 * `getVar()` merges query + body so callers can send params either way. Ports
 * ../../../../proxy.services.vn/app/Controllers/Api/JprsApi.php's payload/
 * response contract onto `service('curlrequest')` transport.
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

        $apiResponse = $this->sendRequest($command);

        if ($apiResponse === false) {
            return $this->response->setJSON([
                'status'         => 'error',
                'api_service_id' => 'jprs.' . $this->service_id,
                'message'        => 'Configuration for service jprs.' . $this->service_id . ' not found.',
            ])->setStatusCode(400);
        }

        ['code' => $code, 'description' => $description, 'data' => $data, 'raw_response' => $raw] =
            $this->parseJprsResponse($apiResponse);

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

    /**
     * Build the JPRS plain-text payload, POST it over the client-cert
     * connection, and return the raw (already ISO-2022-JP -> UTF-8 decoded)
     * response body. Returns `false` when the service config is incomplete.
     */
    private function sendRequest(string $command): string|false
    {
        $config = get_jprs_service_config($this->service_id);

        if (empty($config['AgentLoginID']) || empty($config['AgentLoginPassword']) || empty($config['url'])) {
            return false;
        }

        $payload = [
            'Command'           => $command,
            'AgentLoginID'      => $config['AgentLoginID'],
            'OperationPassword' => $config['AgentLoginPassword'],
        ];

        $params = $this->request->getVar() ?: [];
        $metadata = $params['metadata'] ?? null;
        unset($params['metadata']);

        foreach ($params as $key => $value) {
            $payload[$key] = $value;
        }

        $domain = $payload['DomainName'] ?? null;

        $mergedCert = null;

        try {
            $certDir  = ROOTPATH . 'writable/app_files/jprs.certs/';
            $certPath = $certDir . $config['pem_cert_path'];
            $keyPath  = $certDir . $config['pem_key_path'];

            // ponytail: PEM via curlrequest; P12 fallback only if a P12 service appears
            $mergedCert = $this->buildCombinedPem($certPath, $keyPath);

            $body = $this->buildJprsPlainPayload($payload);
            $body = mb_convert_encoding($body, 'ISO-2022-JP', 'UTF-8');

            $response = service('curlrequest')->post($config['url'], [
                'body'    => $body,
                'headers' => ['Content-Type' => 'text/plain; charset=iso-2022-jp'],
                'cert'    => empty($config['pem_key_pass']) ? $mergedCert : [$mergedCert, $config['pem_key_pass']],
                'verify'  => ($config['ssl_verify'] ?? 'yes') !== 'no', // secure by default: only an explicit 'no' disables mTLS verification
            ]);

            $responseBody = mb_convert_encoding((string) $response->getBody(), 'UTF-8', 'ISO-2022-JP');

            $this->logApiCall([
                'env'          => $this->user->env ?? 'TESTING',
                'service_name' => 'jprs',
                'service_id'   => $this->service_id,
                'url'          => $config['url'],
                'status'       => 1,
                'command'      => $command,
                'domain'       => $domain,
                'params'       => $payload,
                'response'     => $responseBody,
                'metadata'     => $metadata,
            ]);

            return $responseBody;
        } catch (Throwable $e) {
            log_message('error', 'JPRS API Error: ' . $e->getMessage());

            $this->logApiCall([
                'env'          => $this->user->env ?? 'TESTING',
                'service_name' => 'jprs',
                'service_id'   => $this->service_id,
                'url'          => $config['url'],
                'status'       => 0,
                'command'      => $command,
                'domain'       => $domain,
                'params'       => $payload,
                'response'     => ['error' => $e->getMessage()],
                'metadata'     => $metadata,
            ]);

            // 3-digit-status line parser below never matches this, so it
            // surfaces as a generic error envelope without leaking a trace.
            return '500 ' . $e->getMessage();
        } finally {
            if ($mergedCert !== null && is_file($mergedCert)) {
                unlink($mergedCert);
            }
        }
    }

    /**
     * cURL's OpenSSL backend loads the private key from CURLOPT_SSLCERT when
     * CURLOPT_SSLKEY isn't set, so cert+key are concatenated into one temp
     * PEM per call (CI4's native `curlrequest` service has no `ssl_key`
     * option — see report). The passphrase (if any) still unlocks the key
     * via the `cert` option's password slot. Temp file is 0600 and removed
     * in the caller's `finally` block.
     */
    private function buildCombinedPem(string $certPath, string $keyPath): string
    {
        if (! is_file($certPath) || ! is_file($keyPath)) {
            throw new RuntimeException("JPRS certificate or key file not found for service jprs.{$this->service_id}.");
        }

        $merged = tempnam(sys_get_temp_dir(), 'jprs_pem_');
        file_put_contents($merged, file_get_contents($certPath) . "\n" . file_get_contents($keyPath));
        chmod($merged, 0600);

        return $merged;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildJprsPlainPayload(array $payload): string
    {
        $body = '';

        foreach ($payload as $key => $value) {
            if ($key === 'Command') {
                $body .= '*' . $value . "\n";

                continue;
            }

            foreach ((array) $value as $val) {
                $body .= $key . ':' . $val . "\n";
            }
        }

        return rtrim($body, "\n");
    }

    /**
     * Parse the JPRS plain-text response: `Key:Value` lines (repeated keys
     * collapse to `;`-joined) plus the trailing 3-digit status line.
     *
     * @return array{code: ?string, description: ?string, data: array<string, mixed>, raw_response: string[]}
     */
    private function parseJprsResponse(string $responseBody): array
    {
        $lines       = explode("\n", $responseBody);
        $result      = [];
        $rawResponse = [];
        $code        = null;
        $description = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $rawResponse[] = $line;

            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $key           = trim($key);
                $value         = trim($value);

                if (isset($result[$key])) {
                    $result[$key] = (array) $result[$key];
                    $result[$key][] = $value;
                } else {
                    $result[$key] = $value;
                }
            }

            if (preg_match('/^(\d{3})\s+(.+)$/', $line, $matches)) {
                $code        = $matches[1];
                $description = trim($matches[2]);
            }
        }

        foreach ($result as $key => $value) {
            if (is_array($value)) {
                $result[$key] = implode(';', $value);
            }
        }

        return ['code' => $code, 'description' => $description, 'data' => $result, 'raw_response' => $rawResponse];
    }
}
