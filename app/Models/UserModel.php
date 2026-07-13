<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over the existing `users` table (see legacy proxy.services.vn schema).
 * `returnType='object'` so `session('userWorking')->id` etc. keep working
 * exactly as the legacy app expects.
 */
class UserModel extends Model
{
    protected $table            = 'users';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = true;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'name',
        'email',
        'password',
        'zone',
        'env',
        'status',
        'two_factor_method',
        'language',
        'timezone',
        'login_at',
    ];
    protected $useTimestamps = false;
    protected $createdField  = 'created_at';
    protected $updatedField  = 'updated_at';
    protected $deletedField  = 'deleted_at';

    /**
     * Look up an active (non-soft-deleted) user by email. Soft-deleted rows
     * are excluded automatically because `useSoftDeletes` is enabled.
     */
    public function findActiveByEmail(string $email): ?object
    {
        return $this->where('email', $email)->first();
    }

    /**
     * Stamp `login_at` on successful authentication.
     */
    public function stampLogin(int $id): void
    {
        $this->update($id, ['login_at' => date('Y-m-d H:i:s')]);
    }
}
