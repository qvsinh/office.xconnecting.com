<?php

namespace App\Controllers;

/**
 * CNR (RRP) console: identical upstream call/parse/log as the Bearer proxy
 * (`Api\CnrApi::proxy`), via the shared `App\Libraries\CnrClient`
 * (`service('cnrClient')`) — never reimplemented here. Admin and client
 * zones differ only in `requireOwnKey()` (see `Admin\ConsoleCnr` /
 * `Client\ConsoleCnr`).
 */
abstract class CnrConsoleController extends ConsoleController
{
    /**
     * Domains for which certain commands are blocked outright — same table
     * as `Api\CnrApi::DOMAIN_COMMAND_RESTRICTIONS`.
     */
    private const DOMAIN_COMMAND_RESTRICTIONS = [
        'dnp.sg' => ['ModifyDomain'],
    ];

    protected function provider(): string
    {
        return 'cnr';
    }

    protected function domainRestrictionMessage(string $command, array $params): ?string
    {
        $domain = $params['domain'] ?? $params['DomainName'] ?? null;

        if ($domain !== null && in_array($command, self::DOMAIN_COMMAND_RESTRICTIONS[$domain] ?? [], true)) {
            return "Command '{$command}' is not allowed for domain '{$domain}'.";
        }

        return null;
    }

    protected function callUpstream(object $key, string $env, string $command, array $params, int $userId): array
    {
        $response = service('cnrClient')->call(
            (int) $key->service_id,
            $env,
            $command,
            $params,
            ['user_id' => $userId, 'enable_log' => (int) $key->enable_log === 1],
        );

        return [
            'success' => isset($response['code']) && str_starts_with((string) $response['code'], '2'),
            'data'    => $response,
            'message' => $response['description'] ?? 'An error occurred',
        ];
    }
}
