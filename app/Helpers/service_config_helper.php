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

if (! function_exists('list_api_services')) {
    /**
     * Enumerate every registrar service configured in `.env` (scans
     * `cnr.{id}.service_api_id` / `jprs.{id}.service_api_id`) for the API
     * access key create/edit dropdowns. Only the id/provider are surfaced —
     * never a password, PEM path, or agent credential.
     *
     * Reads `$_ENV` rather than the argument-less `getenv()` — CI4's
     * `DotEnv` populates both `putenv()` and `$_ENV` from `.env`, but PHP's
     * no-arg `getenv()` reflects only the SAPI's original process
     * environment snapshot, not values added via `putenv()`/`$_ENV` later
     * in the request (a documented PHP quirk, not a CI4 bug).
     *
     * @return list<array{id: int, provider: string, label: string}>
     */
    function list_api_services(): array
    {
        $services = [];

        foreach ($_ENV as $key => $value) {
            if (! preg_match('/^(cnr|jprs)\.(\d+)\.service_api_id$/', (string) $key, $matches)) {
                continue;
            }

            $provider = $matches[1];
            $id       = (int) $matches[2];

            $services[$id] = [
                'id'       => $id,
                'provider' => $provider,
                'label'    => sprintf('%s %d (%s.%d)', strtoupper($provider), $id, $provider, $id),
            ];
        }

        ksort($services);

        return array_values($services);
    }
}

if (! function_exists('provider_of_service')) {
    /**
     * Reverse-lookup a numeric `service_id` to its provider ('cnr'|'jprs')
     * via `list_api_services()`. Returns null for an unknown/orphaned id —
     * shared by `ConsoleController` (provider-scoped consoles) and
     * `ApiConnectionController` (all-provider self-test) so the lookup
     * can't drift between the two callers.
     */
    function provider_of_service(int $serviceId): ?string
    {
        foreach (list_api_services() as $service) {
            if ($service['id'] === $serviceId) {
                return $service['provider'];
            }
        }

        return null;
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
