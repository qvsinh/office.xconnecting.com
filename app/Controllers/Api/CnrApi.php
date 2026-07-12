<?php

namespace App\Controllers\Api;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * RRP (CNR/RRPProxy) registrar proxy. Stub — fleshed out in Phase 3.
 */
class CnrApi extends ApiController
{
    public function proxy(): ResponseInterface
    {
        return $this->response->setJSON([
            'status'         => 'error',
            'api_service_id' => 'cnr.' . $this->service_id,
            'message'        => 'Not implemented yet',
        ])->setStatusCode(501);
    }
}
