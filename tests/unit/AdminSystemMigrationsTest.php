<?php

use App\Controllers\Admin\System;
use CodeIgniter\Database\MigrationRunner;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Migrations as MigrationsConfig;
use Config\Services;

/**
 * `Admin\System::buildMigrationRows()` — the applied/pending merge behind
 * `/admin/system/migrations`. Exercised with a fake `MigrationRunner`
 * (injected via `Services::injectMock()`) instead of the real migration
 * set, so "one applied, one pending" is deterministic regardless of what's
 * actually been migrated on this machine. The HTTP-rendered page itself is
 * covered once `frontend-coder` adds `admin/system/migrations` (view layer,
 * not yet built — see kanban plan).
 *
 * @internal
 */
final class AdminSystemMigrationsTest extends CIUnitTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        Services::resetSingle('migrations');
    }

    public function testAppliedMigrationShowsBatchAndTimePendingShowsNeither(): void
    {
        $runner = $this->fakeRunner();

        $applied = (object) [
            'version'   => '2026-01-01-000000',
            'class'     => 'App\\Fake\\AppliedMigration',
            'namespace' => 'App',
            'name'      => 'AppliedMigration',
            'path'      => '',
        ];
        $pending = (object) [
            'version'   => '2026-02-01-000000',
            'class'     => 'App\\Fake\\PendingMigration',
            'namespace' => 'App',
            'name'      => 'PendingMigration',
            'path'      => '',
        ];

        $runner->fakeMigrations = [
            $runner->getObjectUid($applied) => $applied,
            $runner->getObjectUid($pending) => $pending,
        ];
        $runner->fakeHistory = [
            (object) [
                'id' => 1, 'version' => $applied->version, 'class' => $applied->class,
                'group' => 'default', 'namespace' => 'App', 'time' => 1_700_000_000, 'batch' => 3,
            ],
        ];

        Services::injectMock('migrations', $runner);

        $rowsByClass = [];
        foreach ((new System())->buildMigrationRows() as $row) {
            $rowsByClass[$row['class']] = $row;
        }

        $this->assertSame('applied', $rowsByClass[$applied->class]['status']);
        $this->assertSame(3, $rowsByClass[$applied->class]['batch']);
        $this->assertSame(1_700_000_000, $rowsByClass[$applied->class]['applied_at']);

        $this->assertSame('pending', $rowsByClass[$pending->class]['status']);
        $this->assertNull($rowsByClass[$pending->class]['batch']);
        $this->assertNull($rowsByClass[$pending->class]['applied_at']);
    }

    /**
     * @return MigrationRunner&object{fakeMigrations: array<string, object>, fakeHistory: list<object>}
     */
    private function fakeRunner(): object
    {
        return new class (config(MigrationsConfig::class)) extends MigrationRunner {
            /** @var array<string, object> */
            public array $fakeMigrations = [];

            /** @var list<object> */
            public array $fakeHistory = [];

            public function findMigrations(): array
            {
                return $this->fakeMigrations;
            }

            public function getHistory(string $group = 'default'): array
            {
                return $this->fakeHistory;
            }
        };
    }
}
