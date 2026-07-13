<?php

namespace App\Models;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Model;
use RuntimeException;

class ApiAccessKeyModel extends Model
{
    /**
     * Per-user cap enforced by createForUser(). Legacy checked
     * `count($existing) > 20` (off-by-one: allowed a 21st key) — this fixes
     * it at the shared root so both create paths get the correct limit.
     */
    public const MAX_KEYS_PER_USER = 20;

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
     * Typed wrapper over `find()` (whose generic signature phpstan can't
     * narrow to `object` from `$returnType`) for callers that need property
     * access on the result.
     */
    public function findKey(int $id): ?object
    {
        return $this->find($id);
    }

    /**
     * Stamp `last_access` on the key row for the given access token.
     */
    public function touchLastAccess(string $accessKey): bool
    {
        return $this->where('access_key', $accessKey)
            ->update(null, ['last_access' => date('Y-m-d H:i:s')]);
    }

    /**
     * Mint a Hashids bearer token compatible with `ApiFilter::before()` and
     * insert the key row. **Centralized here so both the admin and
     * self-service create paths mint identical tokens** — change the encode
     * order once, here, and it can never drift between the two callers.
     *
     * The first 3 decoded elements MUST stay `[user_id, env_numeric,
     * service_id]` — `ApiFilter` reads exactly `decoded[0..2]`.
     *
     * @throws RuntimeException when the user is already at the key cap.
     */
    public function createForUser(object $user, int $serviceId, string $description = ''): string
    {
        helper('app'); // get_hashids()

        $userId = (int) $user->id;

        if ($this->countForUser($userId) >= self::MAX_KEYS_PER_USER) {
            throw new RuntimeException('You can not create more access keys.');
        }

        $envNumeric = $user->env === 'LIVE' ? 1 : 0;
        $accessKey  = get_hashids(32)->encode([$userId, $envNumeric, $serviceId, time(), random_int(1, 1000)]);

        $this->insert([
            'user_id'     => $userId,
            'access_key'  => $accessKey,
            'user_status' => $envNumeric,
            'service_id'  => $serviceId,
            'is_enable'   => 1,
            'enable_log'  => 1,
            'description' => $description,
        ]);

        return $accessKey;
    }

    public function countForUser(int $userId): int
    {
        return $this->where('user_id', $userId)->countAllResults();
    }

    /**
     * @return list<object>
     */
    public function listForUser(int $userId): array
    {
        return $this->where('user_id', $userId)->orderBy('id', 'DESC')->findAll();
    }

    /**
     * Admin list joined with the owning user, keyword-searchable
     * (email exact / numeric id-or-user-or-service / name LIKE).
     *
     * @param array{keyword?: string} $filters
     *
     * @return list<object>
     */
    public function searchAdmin(array $filters, int $limit, int $offset): array
    {
        return $this->adminBuilder($filters)
            ->orderBy('uak.id', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResult();
    }

    /**
     * @param array{keyword?: string} $filters
     */
    public function countAdmin(array $filters): int
    {
        return $this->adminBuilder($filters)->countAllResults();
    }

    /**
     * @param array{keyword?: string} $filters
     */
    private function adminBuilder(array $filters): BaseBuilder
    {
        $builder = $this->db->table($this->table . ' uak')
            ->select('uak.*, u.name AS user_name, u.email AS user_email, u.env AS user_env')
            ->join('users u', 'u.id = uak.user_id', 'left');

        $keyword = trim((string) ($filters['keyword'] ?? ''));

        if ($keyword === '') {
            return $builder;
        }

        if (filter_var($keyword, FILTER_VALIDATE_EMAIL)) {
            $builder->where('u.email', $keyword);
        } elseif (is_numeric($keyword)) {
            $builder->groupStart()
                ->where('uak.id', (int) $keyword)
                ->orWhere('uak.user_id', (int) $keyword)
                ->orWhere('uak.service_id', (int) $keyword)
                ->groupEnd();
        } else {
            $builder->like('u.name', $keyword);
        }

        return $builder;
    }
}
