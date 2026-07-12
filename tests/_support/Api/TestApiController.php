<?php

namespace Tests\Support\Api;

use App\Controllers\Api\ApiController;

/**
 * Minimal concrete subclass exposing `ApiController`'s protected internals
 * (logApiCall gating, fetchHistory) for direct testing without going
 * through the full HTTP/auth request lifecycle.
 */
class TestApiController extends ApiController
{
    public function setUser(int $id, string $env): void
    {
        $this->user = (object) ['id' => $id, 'env' => $env];
    }

    public function setServiceId(int $serviceId): void
    {
        $this->service_id = $serviceId;
    }

    public function setKeyEnableLog(int $enableLog): void
    {
        $this->key_enable_log = $enableLog;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function callLogApiCall(array $params): void
    {
        $this->logApiCall($params);
    }
}
