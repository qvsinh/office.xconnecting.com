<?php

use App\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class UserModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    public function testFindActiveByEmailReturnsActiveUser(): void
    {
        $model = new UserModel();
        $model->insert([
            'name'   => 'Active User',
            'email'  => 'active@example.com',
            'password' => 'irrelevant-hash',
            'zone'   => 'client',
            'status' => 1,
        ]);

        $user = $model->findActiveByEmail('active@example.com');

        $this->assertNotNull($user);
        $this->assertSame('active@example.com', $user->email);
    }

    public function testFindActiveByEmailReturnsNullForSoftDeletedUser(): void
    {
        $model = new UserModel();
        $id    = $model->insert([
            'name'   => 'Deleted User',
            'email'  => 'deleted@example.com',
            'password' => 'irrelevant-hash',
            'zone'   => 'client',
            'status' => 1,
        ]);
        $model->delete($id);

        $this->assertNull($model->findActiveByEmail('deleted@example.com'));
    }

    public function testFindActiveByEmailReturnsNullForUnknownEmail(): void
    {
        $model = new UserModel();

        $this->assertNull($model->findActiveByEmail('nobody@example.com'));
    }

    public function testStampLoginSetsLoginAt(): void
    {
        $model = new UserModel();
        $id    = $model->insert([
            'name'   => 'Login User',
            'email'  => 'login@example.com',
            'password' => 'irrelevant-hash',
            'zone'   => 'client',
            'status' => 1,
        ]);

        $this->assertNull($model->find($id)->login_at);

        $model->stampLogin($id);

        $this->assertNotNull($model->find($id)->login_at);
    }

    public function testSearchAdminFiltersByKeywordAcrossNameEmailCompany(): void
    {
        $model = new UserModel();
        // `company` isn't in `$allowedFields` (mass-assignment isn't needed for
        // it yet — only `searchAdmin()` reads it) -> bypass field protection
        // for this test's setup rows only.
        $model->protect(false)->insert(['name' => 'Alice Admin', 'email' => 'alice@example.com', 'password' => 'x', 'zone' => 'global', 'status' => 1, 'company' => 'Acme']);
        $model->protect(false)->insert(['name' => 'Bob Client', 'email' => 'bob@example.com', 'password' => 'x', 'zone' => 'client', 'status' => 1, 'company' => 'Other Co']);
        $model->protect(false)->insert(['name' => 'Carol Client', 'email' => 'carol@example.com', 'password' => 'x', 'zone' => 'client', 'status' => 0, 'company' => 'Acme Subsidiary']);

        $byName = $model->searchAdmin(['keyword' => 'Alice']);
        $this->assertCount(1, $byName);
        $this->assertSame('alice@example.com', $byName[0]->email);

        $byEmail = $model->searchAdmin(['keyword' => 'bob@example.com']);
        $this->assertCount(1, $byEmail);
        $this->assertSame('Bob Client', $byEmail[0]->name);

        $byCompany = $model->searchAdmin(['keyword' => 'Acme']);
        $this->assertCount(2, $byCompany);
    }

    public function testSearchAdminFiltersByZoneAndStatus(): void
    {
        $model = new UserModel();
        $model->insert(['name' => 'Zone Admin', 'email' => 'zone-admin@example.com', 'password' => 'x', 'zone' => 'global', 'status' => 1]);
        $model->insert(['name' => 'Zone Client Active', 'email' => 'zone-client-active@example.com', 'password' => 'x', 'zone' => 'client', 'status' => 1]);
        $model->insert(['name' => 'Zone Client Inactive', 'email' => 'zone-client-inactive@example.com', 'password' => 'x', 'zone' => 'client', 'status' => 0]);

        $globals = $model->searchAdmin(['zone' => 'global']);
        $this->assertCount(1, $globals);
        $this->assertSame('global', $globals[0]->zone);

        $inactiveClients = $model->searchAdmin(['zone' => 'client', 'status' => 0]);
        $this->assertCount(1, $inactiveClients);
        $this->assertSame('Zone Client Inactive', $inactiveClients[0]->name);
    }

    public function testCountAdminMatchesUnpaginatedRowCountForSameFilters(): void
    {
        $model = new UserModel();
        for ($i = 0; $i < 5; $i++) {
            $model->insert(['name' => "Paged User {$i}", 'email' => "paged{$i}@example.com", 'password' => 'x', 'zone' => 'client', 'status' => 1]);
        }

        $filters = ['zone' => 'client'];
        $page    = $model->searchAdmin($filters, 'login_at', 'desc', 2, 0);

        $this->assertCount(2, $page);
        $this->assertSame(5, $model->countAdmin($filters));
    }

    public function testSearchAdminExcludesSoftDeletedRows(): void
    {
        $model = new UserModel();
        $id    = $model->insert(['name' => 'Deleted Search User', 'email' => 'deleted-search@example.com', 'password' => 'x', 'zone' => 'client', 'status' => 1]);
        $model->delete($id);

        $this->assertSame([], $model->searchAdmin(['keyword' => 'Deleted Search User']));
        $this->assertSame(0, $model->countAdmin(['keyword' => 'Deleted Search User']));
    }

    public function testCountActiveAdminsCountsOnlyActiveGlobalUsers(): void
    {
        $model = new UserModel();
        $model->insert(['name' => 'Active Admin', 'email' => 'active-admin@example.com', 'password' => 'x', 'zone' => 'global', 'status' => 1]);
        $model->insert(['name' => 'Inactive Admin', 'email' => 'inactive-admin@example.com', 'password' => 'x', 'zone' => 'global', 'status' => 0]);
        $model->insert(['name' => 'Active Client', 'email' => 'active-client@example.com', 'password' => 'x', 'zone' => 'client', 'status' => 1]);
        $deletedAdminId = $model->insert(['name' => 'Deleted Admin', 'email' => 'deleted-admin@example.com', 'password' => 'x', 'zone' => 'global', 'status' => 1]);
        $model->delete($deletedAdminId);

        $this->assertSame(1, $model->countActiveAdmins());
    }
}
