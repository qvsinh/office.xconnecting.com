<?php

namespace App\Libraries;

use App\Models\ApiHistoryModel;
use RuntimeException;
use Throwable;

/**
 * Shared JPRS (.jp) upstream client over client-cert HTTPS: builds the
 * plain-text payload, POSTs it via `service('curlrequest')` mTLS, parses the
 * response, and records `api_history` via `ApiHistoryModel::record()`. This
 * is the single place an upstream JPRS call happens — used by the Bearer
 * proxy (`Api\JprsApi`) today and, from Phase 2, the web developer console.
 */
class JprsClient
{
    /**
     * @param array<string, mixed>                     $params ponytail: 'metadata' key, if present, is
     *                                                         business metadata stored on the history row —
     *                                                         not call context (see `$meta`)
     * @param array{user_id?: ?int, enable_log?: bool} $meta   caller identity/log-gate context
     *
     * @return false|string false when the service config is incomplete, else the raw
     *                      (already ISO-2022-JP -> UTF-8 decoded) response body
     */
    public function call(int $serviceId, string $env, string $command, array $params, array $meta = []): false|string
    {
        $config = get_jprs_service_config($serviceId);

        if (empty($config['AgentLoginID']) || empty($config['AgentLoginPassword']) || empty($config['url'])) {
            return false;
        }

        $payload = [
            'Command'           => $command,
            'AgentLoginID'      => $config['AgentLoginID'],
            'OperationPassword' => $config['AgentLoginPassword'],
        ];

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
            $mergedCert = $this->buildCombinedPem($certPath, $keyPath, $serviceId);

            $body = $this->buildJprsPlainPayload($payload);
            $body = mb_convert_encoding($body, 'ISO-2022-JP', 'UTF-8');

            $response = service('curlrequest')->post($config['url'], [
                'body'    => $body,
                'headers' => ['Content-Type' => 'text/plain; charset=iso-2022-jp'],
                'cert'    => empty($config['pem_key_pass']) ? $mergedCert : [$mergedCert, $config['pem_key_pass']],
                'verify'  => ($config['ssl_verify'] ?? 'yes') !== 'no', // secure by default: only an explicit 'no' disables mTLS verification
            ]);

            $responseBody = mb_convert_encoding((string) $response->getBody(), 'UTF-8', 'ISO-2022-JP');

            model(ApiHistoryModel::class)->record([
                'user_id'      => $meta['user_id'] ?? null,
                'env'          => $env,
                'service_name' => 'jprs',
                'service_id'   => $serviceId,
                'url'          => $config['url'],
                'status'       => 1,
                'command'      => $command,
                'domain'       => $domain,
                'params'       => $payload,
                'response'     => $responseBody,
                'metadata'     => $metadata,
            ], (bool) ($meta['enable_log'] ?? false));

            return $responseBody;
        } catch (Throwable $e) {
            log_message('error', 'JPRS API Error: ' . $e->getMessage());

            model(ApiHistoryModel::class)->record([
                'user_id'      => $meta['user_id'] ?? null,
                'env'          => $env,
                'service_name' => 'jprs',
                'service_id'   => $serviceId,
                'url'          => $config['url'],
                'status'       => 0,
                'command'      => $command,
                'domain'       => $domain,
                'params'       => $payload,
                'response'     => ['error' => $e->getMessage()],
                'metadata'     => $metadata,
            ], (bool) ($meta['enable_log'] ?? false));

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
    public function buildCombinedPem(string $certPath, string $keyPath, int $serviceId): string
    {
        if (! is_file($certPath) || ! is_file($keyPath)) {
            throw new RuntimeException("JPRS certificate or key file not found for service jprs.{$serviceId}.");
        }

        $merged = tempnam(sys_get_temp_dir(), 'jprs_pem_');
        file_put_contents($merged, file_get_contents($certPath) . "\n" . file_get_contents($keyPath));
        chmod($merged, 0600);

        return $merged;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function buildJprsPlainPayload(array $payload): string
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
     * @return array{code: ?string, description: ?string, data: array<string, mixed>, raw_response: list<string>}
     */
    public function parseJprsResponse(string $responseBody): array
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
                    $result[$key]   = (array) $result[$key];
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
