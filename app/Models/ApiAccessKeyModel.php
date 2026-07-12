<?php

namespace App\Models;

use CodeIgniter\Model;

class ApiAccessKeyModel extends Model
{
    protected $table            = 'users_api_access_keys';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id',
        'access_key',
        'user_status',
        'service_id',
        'is_enable',
        'enable_log',
        'description',
        'last_access',
    ];
    protected $useTimestamps  = false;
    protected $createdField   = 'created_at';
    protected $updatedField   = '';
    protected $deletedField   = '';
    protected $skipValidation = false;

    /**
     * Fetch a key row by its plain access token.
     */
    public function findByAccessKey(string $accessKey): ?object
    {
        return $this->where('access_key', $accessKey)->first();
    }

    /**
     * Stamp `last_access` on the key row for the given access token.
     */
    public function touchLastAccess(string $accessKey): bool
    {
        return $this->where('access_key', $accessKey)
            ->update(null, ['last_access' => date('Y-m-d H:i:s')]);
    }
}
