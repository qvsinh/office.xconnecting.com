<?php

use App\Models\NotificationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * @internal
 */
final class NotificationModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    // null = migrate/regress every namespace (App's migrations aren't under
    // the default 'Tests\Support' namespace CI4 scopes migrations to).
    protected $namespace = null;

    public function testListForUserScopesOrdersAndPaginates(): void
    {
        $this->seedRow(['user_id' => 1, 'message' => 'first for user 1']);
        $olderId = $this->seedLastInsertId();
        $this->seedRow(['user_id' => 1, 'message' => 'second for user 1']);
        $newerId = $this->seedLastInsertId();
        $this->seedRow(['user_id' => 2, 'message' => 'for user 2']);

        $model = new NotificationModel();
        $rows  = $model->listForUser(1, 10, 0);

        $this->assertCount(2, $rows);
        $this->assertSame($newerId, (int) $rows[0]->id); // newest first
        $this->assertSame($olderId, (int) $rows[1]->id);

        // Pagination: limit 1 offset 1 returns only the older row.
        $page2 = $model->listForUser(1, 1, 1);
        $this->assertCount(1, $page2);
        $this->assertSame($olderId, (int) $page2[0]->id);
    }

    public function testCountForUserAndUnreadCountForUserAreScopedPerUser(): void
    {
        $this->seedRow(['user_id' => 1, 'is_read' => 0]);
        $this->seedRow(['user_id' => 1, 'is_read' => 1]);
        $this->seedRow(['user_id' => 2, 'is_read' => 0]);

        $model = new NotificationModel();

        $this->assertSame(2, $model->countForUser(1));
        $this->assertSame(1, $model->unreadCountForUser(1));
        $this->assertSame(1, $model->countForUser(2));
        $this->assertSame(1, $model->unreadCountForUser(2));
    }

    public function testRecentForUserCapsToLimitNewestFirst(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->seedRow(['user_id' => 1, 'message' => "row {$i}"]);
        }

        $model = new NotificationModel();
        $rows  = $model->recentForUser(1, 2);

        $this->assertCount(2, $rows);
    }

    public function testMarkReadSetsIsReadForOwnedRow(): void
    {
        $this->seedRow(['user_id' => 1, 'is_read' => 0]);
        $id = $this->seedLastInsertId();

        $model = new NotificationModel();
        $this->assertTrue($model->markRead($id, 1));
        $this->assertSame(1, (int) $model->find($id)->is_read);
    }

    public function testMarkReadReturnsFalseAndDoesNotMutateAForeignRow(): void
    {
        $this->seedRow(['user_id' => 1, 'is_read' => 0]);
        $id = $this->seedLastInsertId();

        $model = new NotificationModel();
        $this->assertFalse($model->markRead($id, 2)); // foreign user

        $this->assertSame(0, (int) $model->find($id)->is_read); // untouched
    }

    public function testMarkReadReturnsFalseForMissingId(): void
    {
        $model = new NotificationModel();
        $this->assertFalse($model->markRead(999999, 1));
    }

    public function testMarkAllReadOnlyAffectsTheCallersUnreadRows(): void
    {
        $this->seedRow(['user_id' => 1, 'is_read' => 0]);
        $unreadA = $this->seedLastInsertId();
        $this->seedRow(['user_id' => 1, 'is_read' => 1]);
        $alreadyReadA = $this->seedLastInsertId();
        $this->seedRow(['user_id' => 2, 'is_read' => 0]);
        $unreadB = $this->seedLastInsertId();

        $model   = new NotificationModel();
        $affected = $model->markAllRead(1);

        $this->assertSame(1, $affected); // only the one unread row for user 1
        $this->assertSame(1, (int) $model->find($unreadA)->is_read);
        $this->assertSame(1, (int) $model->find($alreadyReadA)->is_read); // unchanged, still read
        $this->assertSame(0, (int) $model->find($unreadB)->is_read); // other user untouched
    }

    public function testMarkAllReadReturnsZeroWhenNothingUnread(): void
    {
        $this->seedRow(['user_id' => 1, 'is_read' => 1]);

        $model = new NotificationModel();
        $this->assertSame(0, $model->markAllRead(1));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedRow(array $overrides = []): void
    {
        model(NotificationModel::class)->insert(array_merge([
            'user_id' => 1,
            'task_id' => null,
            'message' => 'test notification',
            'is_read' => 0,
        ], $overrides));
    }

    private function seedLastInsertId(): int
    {
        return (int) model(NotificationModel::class)->getInsertID();
    }
}
