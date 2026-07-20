<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Over the `api_keys` table — personal API keys for the REST API (see
 * `kanban/processing/20260721-00-master-social-hub.md`). Only
 * `token_prefix` + `token_hash` are ever persisted; the plaintext token is
 * returned once, at creation time, by `createForUser()` and never stored or
 * logged again.
 */
class ApiKeyModel extends Model
{
    protected $table            = 'api_keys';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'object';
    protected $useSoftDeletes   = false;
    protected $protectFields    = true;
    protected $allowedFields    = [
        'user_id', 'name', 'token_prefix', 'token_hash', 'scopes',
        'last_used_at', 'expires_at', 'revoked_at',
    ];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';

    /**
     * A user's keys, newest first. Never selects `token_hash` — the list
     * view only ever needs the prefix, name, scopes, and status columns.
     *
     * @return list<object>
     */
    public function forUser(int $userId): array
    {
        return $this->select('id, user_id, name, token_prefix, scopes, last_used_at, expires_at, revoked_at, created_at')
            ->where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->findAll();
    }

    /**
     * Generates a new personal access token for the user and stores only
     * its prefix + sha256 hash — the plaintext is returned once and must
     * never be persisted or logged by the caller.
     *
     * @return array{plain: string, row: object}
     */
    public function createForUser(int $userId, string $name, string $scopes = 'read', ?string $expiresAt = null): array
    {
        $plain  = 'sk_' . bin2hex(random_bytes(20));
        $prefix = substr($plain, 0, 12);

        $id = (int) $this->insert([
            'user_id'      => $userId,
            'name'         => $name,
            'token_prefix' => $prefix,
            'token_hash'   => hash('sha256', $plain),
            'scopes'       => $scopes,
            'expires_at'   => $expiresAt,
        ]);

        return ['plain' => $plain, 'row' => $this->find($id)];
    }

    /**
     * Resolves a plaintext bearer token to its `api_keys` row (with
     * `user_id`) for the REST API auth filter — prefix lookup narrows to a
     * single candidate row, then `hash_equals()` on the sha256 hash performs
     * the actual (timing-safe) comparison. Rejects revoked or expired keys.
     * On a hit, stamps `last_used_at`.
     */
    public function resolve(string $plain): ?object
    {
        if (strlen($plain) < 12) {
            return null;
        }

        $prefix = substr($plain, 0, 12);
        $row    = $this->where('token_prefix', $prefix)->first();

        if (! is_object($row) || ! hash_equals($row->token_hash, hash('sha256', $plain))) {
            return null;
        }

        if ($row->revoked_at !== null) {
            return null;
        }

        if ($row->expires_at !== null && $row->expires_at < date('Y-m-d H:i:s')) {
            return null;
        }

        $this->update($row->id, ['last_used_at' => date('Y-m-d H:i:s')]);

        return $row;
    }

    /**
     * Revokes a key, scoped to its owner. Returns false (no mutation) if the
     * row is missing or belongs to someone else — no existence leak, no
     * IDOR.
     */
    public function revoke(int $id, int $userId): bool
    {
        $row = $this->where('id', $id)->where('user_id', $userId)->first();

        if ($row === null) {
            return false;
        }

        return (bool) $this->update($id, ['revoked_at' => date('Y-m-d H:i:s')]);
    }
}
