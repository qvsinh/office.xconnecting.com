<?php

/**
 * Service Config Helper
 *
 * Reads registrar service credentials/config from `.env` by numeric service id,
 * e.g. `cnr.800.name`, `jprs.801.url`. Secrets never hardcoded — always `getenv()`.
 *
 * Usage:
 *  helper('service_config');
 *  $cfg = get_cnr_service_config(800);
 *  $cfg = get_jprs_service_config(801);
 */
if (! function_exists('get_cnr_service_config')) {
    /**
     * @return array{service_api_id: (string|null), url: (string|null), name: (string|null), password: (string|null)}
     */
    function get_cnr_service_config(int|string $id): array
    {
        $idStr = (string) $id;

        return [
            'service_api_id' => getenv('cnr.' . $idStr . '.service_api_id') ?: null,
            'url'            => getenv('cnr.' . $idStr . '.url') ?: null,
            'name'           => getenv('cnr.' . $idStr . '.name') ?: null,
            'password'       => getenv('cnr.' . $idStr . '.password') ?: null,
        ];
    }
}

if (! function_exists('get_jprs_service_config')) {
    /**
     * @return array{service_api_id: (string|null), url: (string|null), AgentLoginID: (string|null),
     *     AgentLoginPassword: (string|null), pem_cert_path: (string|null), pem_key_path: (string|null),
     *     pem_key_pass: (string|null), ssl_verify: (string|null)}
     */
    function get_jprs_service_config(int|string $id): array
    {
        $idStr = (string) $id;

        return [
            'service_api_id'     => getenv('jprs.' . $idStr . '.service_api_id') ?: null,
            'url'                => getenv('jprs.' . $idStr . '.url') ?: null,
            'AgentLoginID'       => getenv('jprs.' . $idStr . '.AgentLoginID') ?: null,
            'AgentLoginPassword' => getenv('jprs.' . $idStr . '.AgentLoginPassword') ?: null,
            'pem_cert_path'      => getenv('jprs.' . $idStr . '.pem_cert_path') ?: null,
            'pem_key_path'       => getenv('jprs.' . $idStr . '.pem_key_path') ?: null,
            'pem_key_pass'       => getenv('jprs.' . $idStr . '.pem_key_pass') ?: null,
            'ssl_verify'         => getenv('jprs.' . $idStr . '.ssl_verify') ?: null,
        ];
    }
}

if (! function_exists('get_service_config')) {
    /**
     * Auto-detect provider (JPRS then CNR) by numeric id and return its config.
     * Unknown id returns a null-filled `service_api_id`/`url` array.
     *
     * @return array{service_api_id: (string|null), url: (string|null)}
     */
    function get_service_config(int|string $id): array
    {
        $jprs = get_jprs_service_config($id);
        if ($jprs['service_api_id'] !== null || $jprs['url'] !== null) {
            return ['service_api_id' => $jprs['service_api_id'], 'url' => $jprs['url']];
        }

        $cnr = get_cnr_service_config($id);
        if ($cnr['service_api_id'] !== null || $cnr['url'] !== null) {
            return ['service_api_id' => $cnr['service_api_id'], 'url' => $cnr['url']];
        }

        return ['service_api_id' => null, 'url' => null];
    }
}
