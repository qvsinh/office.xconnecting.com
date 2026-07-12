<?php

namespace Tests\Support\Api;

use App\Models\ApiAccessKeyModel;

/**
 * Seeds `users_api_access_keys` rows and mints matching Hashids Bearer
 * tokens for Feature-level API proxy tests.
 */
trait ApiKeyFixture
{
    /**
     * @param array<string, mixed> $overrides Row overrides (e.g. is_enable, enable_log).
     *
     * @return array{token: string, id: int}
     */
    protected function seedApiKey(int $userId, int $envFlag, int $serviceId, array $overrides = []): array
    {
        helper('app');

        $token = get_hashids(32)->encode($userId, $envFlag, $serviceId);

        $row = array_merge([
            'user_id'    => $userId,
            'access_key' => $token,
            'service_id' => $serviceId,
            'is_enable'  => 1,
            'enable_log' => 1,
        ], $overrides, ['access_key' => $token]);

        $id = model(ApiAccessKeyModel::class)->insert($row);

        return ['token' => $token, 'id' => $id];
    }
}
