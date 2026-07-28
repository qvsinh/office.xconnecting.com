<?php

use App\Models\NotificationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Per-user notification feed/badge/mark-read (`Notifications`), shared by
 * both admin and client sessions and scoped strictly to
 * `session('userWorking')`. Exercised through real routing + filters
 * (`authFilter`) + CSRF. Model-level scoping coverage lives in
 * NotificationModelTest — this suite is transport, auth-gating, JSON
 * envelope shape, and the IDOR contract on mark-read.
 *
 * `index()` (the full `/notifications` page) is not exercised here — its
 * view (`app/Views/notifications/index.php`) is built by frontend-coder in
 * Phase 2 and does not exist yet.
 *
 * @internal
 */
final class NotificationsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testGuestIsRedirectedToLoginFromFeed(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/notifications/feed');

        $result->assertRedirectTo('/login');
    }

    public function testGuestIsRedirectedToLoginFromMarkRead(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->post('/notifications/mark-read', $this->withCsrf(['id' => 1]));

        $result->assertRedirectTo('/login');
    }

    public function testMutationWithoutCsrfTokenIsRejected(): void
    {
        $user = $this->seedWebUser(['email' => 'nocsrf-notif@example.com', 'zone' => 'user']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/notifications/mark-read', ['id' => 1]);

        // security.redirect = true (.env) -> CSRF::before() redirects on failure.
        $result->assertRedirect();
    }

    public function testUnreadCountReturnsSuccessEnvelopeScopedToCaller(): void
    {
        $userA = $this->seedWebUser(['email' => 'unread-a@example.com', 'zone' => 'user']);
        $userB = $this->seedWebUser(['email' => 'unread-b@example.com', 'zone' => 'user']);
        $this->seedRow(['user_id' => $userA->id, 'is_read' => 0]);
        $this->seedRow(['user_id' => $userA->id, 'is_read' => 1]);
        $this->seedRow(['user_id' => $userB->id, 'is_read' => 0]);

        $this->loginAs($userA);
        $result = $this->withSession()->get('/notifications/unread-count');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertArrayHasKey('csrf_hash', $json);
        $this->assertSame(1, $json['unread_count']); // only userA's unread row
    }

    public function testFeedReturnsRecentItemsAndUnreadCountScopedToCaller(): void
    {
        $userA = $this->seedWebUser(['email' => 'feed-a@example.com', 'zone' => 'user']);
        $userB = $this->seedWebUser(['email' => 'feed-b@example.com', 'zone' => 'user']);
        $this->seedRow(['user_id' => $userA->id, 'message' => 'for A', 'is_read' => 0]);
        $this->seedRow(['user_id' => $userB->id, 'message' => 'for B', 'is_read' => 0]);

        $this->loginAs($userA);
        $result = $this->withSession()->get('/notifications/feed');

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertArrayHasKey('csrf_hash', $json);
        $this->assertSame(1, $json['unread_count']);
        $this->assertCount(1, $json['items']);
        $this->assertSame('for A', $json['items'][0]['message']);
    }

    public function testMarkReadRejectsAForeignRowAndLeavesItUnread(): void
    {
        [$userA, $userB, $rowA] = $this->seedTwoUsersAndARowForA();

        $this->loginAs($userB);
        $result = $this->withSession()->post('/notifications/mark-read', $this->withCsrf(['id' => $rowA]));

        $result->assertStatus(404);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('failed', $json['status']);
        $this->assertSame(0, (int) model(NotificationModel::class)->find($rowA)->is_read);
    }

    public function testMarkReadMarksTheCallersOwnRowAndReturnsFreshUnreadCount(): void
    {
        [$userA, , $rowA] = $this->seedTwoUsersAndARowForA();

        $this->loginAs($userA);
        $result = $this->withSession()->post('/notifications/mark-read', $this->withCsrf(['id' => $rowA]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame(0, $json['unread_count']);
        $this->assertSame(1, (int) model(NotificationModel::class)->find($rowA)->is_read);
    }

    public function testMarkReadWithMissingIdReturns404(): void
    {
        $user = $this->seedWebUser(['email' => 'missing-id@example.com', 'zone' => 'user']);
        $this->loginAs($user);

        $result = $this->withSession()->post('/notifications/mark-read', $this->withCsrf(['id' => 999999]));

        $result->assertStatus(404);
    }

    public function testMarkAllReadOnlyAffectsTheCallersRowsAndReturnsZeroUnread(): void
    {
        $userA = $this->seedWebUser(['email' => 'markall-a@example.com', 'zone' => 'user']);
        $userB = $this->seedWebUser(['email' => 'markall-b@example.com', 'zone' => 'user']);
        $this->seedRow(['user_id' => $userA->id, 'is_read' => 0]);
        $this->seedRow(['user_id' => $userA->id, 'is_read' => 0]);
        $otherUnread = $this->seedRow(['user_id' => $userB->id, 'is_read' => 0]);

        $this->loginAs($userA);
        $result = $this->withSession()->post('/notifications/mark-all-read', $this->withCsrf([]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame(0, $json['unread_count']);

        $model = model(NotificationModel::class);
        $this->assertSame(0, $model->unreadCountForUser($userA->id));
        $this->assertSame(0, (int) $model->find($otherUnread)->is_read); // untouched -> still unread
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedRow(array $overrides = []): int
    {
        return model(NotificationModel::class)->insert(array_merge([
            'user_id' => 1,
            'task_id' => null,
            'message' => 'test notification',
            'is_read' => 0,
        ], $overrides), true);
    }

    /**
     * @return array{0: object, 1: object, 2: int} [userA, userB, rowA id]
     */
    private function seedTwoUsersAndARowForA(): array
    {
        $userA = $this->seedWebUser(['email' => 'notif-a@example.com', 'zone' => 'user']);
        $userB = $this->seedWebUser(['email' => 'notif-b@example.com', 'zone' => 'user']);
        $rowA  = $this->seedRow(['user_id' => $userA->id, 'is_read' => 0]);

        return [$userA, $userB, $rowA];
    }
}
