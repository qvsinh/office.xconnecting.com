<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * JPRS (.jp) registrar proxy over client-cert HTTPS. Stub — fleshed out in Phase 4.
 */
class JprsApi extends ApiController
{
    public function proxy(): ResponseInterface
    {
        return $this->response->setJSON([
            'status'         => 'error',
            'api_service_id' => 'jprs.' . $this->service_id,
            'message'        => 'Not implemented yet',
        ])->setStatusCode(501);
    }
}
