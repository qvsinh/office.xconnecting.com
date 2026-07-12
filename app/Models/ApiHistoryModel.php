<?php

namespace App\Models;

use CodeIgniter\Model;

class ApiHistoryModel extends Model
{
    protected $table            = 'api_history';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'env',
        'service_name',
        'service_id',
        'user_id',
        'url',
        'command',
        'domain',
        'params',
        'response',
        'metadata',
        'status',
    ];

    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected $deletedField  = 'deleted_at';

    protected $skipValidation = false;
}
