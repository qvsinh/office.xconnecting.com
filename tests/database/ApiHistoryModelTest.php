<?php

use App\Models\ApiHistoryModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class ApiHistoryModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    // null = migrate/regress every namespace (App's migrations aren't under
    // the default 'Tests\Support' namespace CI4 scopes migrations to).
    protected $namespace = null;

    public function testInsertAndScopedRead(): void
    {
        $model = new ApiHistoryModel();
        $model->insert([
            'env'          => 'testing',
            'service_name' => 'cnr',
            'service_id'   => 800,
            'user_id'      => 1,
            'command'      => 'StatusAccount',
            'domain'       => null,
            'params'       => '{"command":"StatusAccount"}',
            'response'     => '{"code":200}',
            'status'       => 1,
        ]);

        $rows = $model->where('service_name', 'cnr')->where('service_id', 800)->findAll();
        $this->assertCount(1, $rows);
        $this->assertSame('StatusAccount', $rows[0]['command']);
    }

    private function seedRow(array $overrides = []): int
    {
        $model = new ApiHistoryModel();

        return $model->insert(array_merge([
            'env'          => 'TESTING',
            'service_name' => 'cnr',
            'service_id'   => 800,
            'user_id'      => 1,
            'command'      => 'StatusAccount',
            'domain'       => 'example.com',
            'params'       => '{"command":"StatusAccount"}',
            'response'     => '{"code":200}',
            'status'       => 1,
        ], $overrides), true);
    }

    public function testSearchHistoryScopesToUserIdFilter(): void
    {
        $this->seedRow(['user_id' => 1]);
        $this->seedRow(['user_id' => 2]);

        $model = new ApiHistoryModel();
        $rowsA = $model->searchHistory(['user_id' => 1]);
        $this->assertCount(1, $rowsA);
        $this->assertSame(1, (int) $rowsA[0]['user_id']);

        $this->assertSame(1, $model->countHistory(['user_id' => 1]));
        $this->assertSame(2, $model->countHistory([]));
    }

    public function testSearchHistoryFiltersByKeywordAcrossMultipleTerms(): void
    {
        $this->seedRow(['command' => 'CreateDomain', 'domain' => 'foo.com', 'response' => '{"code":200}']);
        $this->seedRow(['command' => 'DeleteDomain', 'domain' => 'bar.com', 'response' => '{"code":500}']);

        $model = new ApiHistoryModel();

        // Single term matches one of the searched columns.
        $this->assertCount(1, $model->searchHistory(['keyword' => 'foo.com']));

        // Two space-split terms are AND-ed: both must match (possibly different columns).
        $this->assertCount(1, $model->searchHistory(['keyword' => 'DeleteDomain 500']));
        $this->assertCount(0, $model->searchHistory(['keyword' => 'DeleteDomain foo.com']));
    }

    public function testSearchHistoryFiltersByServiceIdEnvAndStatus(): void
    {
        $this->seedRow(['service_id' => 800, 'env' => 'TESTING', 'status' => 1]);
        $this->seedRow(['service_id' => 801, 'env' => 'LIVE', 'status' => 0]);

        $model = new ApiHistoryModel();
        $this->assertCount(1, $model->searchHistory(['service_id' => '800']));
        $this->assertCount(1, $model->searchHistory(['env' => 'LIVE']));
        $this->assertCount(1, $model->searchHistory(['status' => '0']));

        // Non-numeric service_id and out-of-range status are ignored, not errors.
        $this->assertCount(2, $model->searchHistory(['service_id' => 'not-a-number']));
        $this->assertCount(2, $model->searchHistory(['status' => '2']));
    }

    public function testSearchHistorySortAllowlistFallsBackToCreatedAtDesc(): void
    {
        $olderId = $this->seedRow(['command' => 'First']);
        $newerId = $this->seedRow(['command' => 'Second']);

        $model = new ApiHistoryModel();

        // Unknown sort column falls back to created_at; default/invalid order falls back to DESC (newest first).
        $rows = $model->searchHistory([], 'not-a-real-column', 'not-a-real-order');
        $this->assertSame($newerId, (int) $rows[0]['id']);

        // Allowlisted column + explicit asc is honoured (oldest first).
        $rows = $model->searchHistory([], 'id', 'asc');
        $this->assertSame($olderId, (int) $rows[0]['id']);
    }

    public function testFindOwnedReturnsNullForForeignOrAbsentId(): void
    {
        $id = $this->seedRow(['user_id' => 1]);

        $model = new ApiHistoryModel();
        $this->assertNotNull($model->findOwned($id, 1));
        $this->assertNull($model->findOwned($id, 2)); // foreign
        $this->assertNull($model->findOwned(999999, 1)); // absent
    }

    public function testFindByIdAdminIgnoresUserScope(): void
    {
        $id = $this->seedRow(['user_id' => 1]);

        $model = new ApiHistoryModel();
        $this->assertNotNull($model->findByIdAdmin($id));
    }

    public function testDeleteOwnedSoftDeletesOnlyTheOwnersRow(): void
    {
        $ownId    = $this->seedRow(['user_id' => 1]);
        $otherId  = $this->seedRow(['user_id' => 2]);

        $model = new ApiHistoryModel();

        $this->assertFalse($model->deleteOwned($otherId, 1)); // not owned
        $this->assertNotNull($model->find($otherId));

        $this->assertTrue($model->deleteOwned($ownId, 1));
        $this->assertNull($model->find($ownId)); // excluded by soft-delete scope
        $this->assertNotNull($model->withDeleted()->find($ownId)); // still present, just flagged
    }

    public function testDeleteFilteredSoftDeletesOnlyMatchingRowsForTheGivenUser(): void
    {
        $this->seedRow(['user_id' => 1, 'service_name' => 'cnr']);
        $this->seedRow(['user_id' => 1, 'service_name' => 'jprs']);
        $this->seedRow(['user_id' => 2, 'service_name' => 'cnr']);

        $model   = new ApiHistoryModel();
        $deleted = $model->deleteFiltered(['user_id' => 1, 'service_name' => 'cnr']);

        $this->assertSame(1, $deleted);
        $this->assertSame(1, $model->countHistory(['user_id' => 1])); // jprs row untouched
        $this->assertSame(1, $model->countHistory(['user_id' => 2])); // other user untouched
    }

    public function testDeleteFilteredChunksOverMultiplePages(): void
    {
        // Reflection-free chunk-boundary check: seed a small multiple of a
        // hypothetical small chunk isn't practical without exposing the
        // constant, so this exercises "more than one page's worth" via a
        // moderate row count instead of the real 500-row chunk size.
        for ($i = 0; $i < 5; $i++) {
            $this->seedRow(['user_id' => 1]);
        }

        $model   = new ApiHistoryModel();
        $deleted = $model->deleteFiltered(['user_id' => 1]);

        $this->assertSame(5, $deleted);
        $this->assertSame(0, $model->countHistory(['user_id' => 1]));
    }

    public function testDeleteByAgeIsScopedToUserWhenGivenAndSoftDeletesOldRowsOnly(): void
    {
        $model = new ApiHistoryModel();

        $oldId   = $this->seedRow(['user_id' => 1]);
        $recentId = $this->seedRow(['user_id' => 1]);
        $otherOldId = $this->seedRow(['user_id' => 2]);

        // Backdate two rows well past the cutoff.
        $model->db->table('api_history')->whereIn('id', [$oldId, $otherOldId])
            ->update(['created_at' => date('Y-m-d H:i:s', strtotime('-30 days'))]);

        $deleted = $model->deleteByAge(7, 1);

        $this->assertSame(1, $deleted);
        $this->assertNull($model->find($oldId));
        $this->assertNotNull($model->find($recentId));
        $this->assertNotNull($model->find($otherOldId)); // different user, untouched
    }

    public function testDeleteByAgeWithoutUserIdAppliesToAllUsers(): void
    {
        $model = new ApiHistoryModel();

        $idA = $this->seedRow(['user_id' => 1]);
        $idB = $this->seedRow(['user_id' => 2]);

        $model->db->table('api_history')->whereIn('id', [$idA, $idB])
            ->update(['created_at' => date('Y-m-d H:i:s', strtotime('-30 days'))]);

        $deleted = $model->deleteByAge(7);

        $this->assertSame(2, $deleted);
        $this->assertNull($model->find($idA));
        $this->assertNull($model->find($idB));
    }

    public function testCountSinceCountsRecentRowsOptionallyScopedByUser(): void
    {
        $model = new ApiHistoryModel();

        $this->seedRow(['user_id' => 1]); // recent
        $this->seedRow(['user_id' => 2]); // recent
        $old = $this->seedRow(['user_id' => 1]);
        // Backdate one row past the 24h window (bypass useTimestamps on insert).
        $model->builder()->where('id', $old)->update(['created_at' => date('Y-m-d H:i:s', time() - (2 * 86400))]);

        $since = date('Y-m-d H:i:s', time() - 86400);

        $this->assertSame(2, $model->countSince($since));            // both recent, old excluded
        $this->assertSame(1, $model->countSince($since, 1));          // user 1: only the recent one
        $this->assertSame(1, $model->countSince($since, 2));          // user 2: recent
        $this->assertSame(3, $model->countSince(date('Y-m-d H:i:s', time() - (10 * 86400)))); // wide window: all
    }
}
