<?php

use App\Models\NotificationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * View-rendering coverage for the notifications list page
 * (`notifications/index.php`) and the shared topbar bell
 * (`layouts/app.php`). Controller/model logic (IDOR, scoping, JSON
 * envelopes) is already covered by NotificationModelTest and
 * NotificationsTest — this suite closes the remaining gap: does the list
 * page actually render, is it owner-scoped, is the stored `message` safe
 * from XSS, and does every authenticated page carry the bell + sidebar link.
 *
 * @internal
 */
final class NotificationsViewTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    private const XSS_SCRIPT = '<script>alert(1)</script>';

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        parent::tearDown();
        session()->destroy();
    }

    public function testIndexRendersListPageWithMarkAllReadControl(): void
    {
        $user = $this->seedWebUser(['email' => 'view-notif-index@example.com', 'zone' => 'client']);
        $this->seedRow(['user_id' => $user->id, 'message' => 'first message']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/notifications');

        $result->assertStatus(200);
        $result->assertSee('Notifications');
        $result->assertSee('Mark all as read');
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('notificationsPage(', $body);
    }

    public function testIndexShowsPaginationWhenTotalExceedsPageSize(): void
    {
        $user = $this->seedWebUser(['email' => 'view-notif-paginate@example.com', 'zone' => 'client']);
        for ($i = 0; $i < 16; $i++) {
            $this->seedRow(['user_id' => $user->id, 'message' => "msg {$i}"]);
        }
        $this->loginAs($user);

        $result = $this->withSession()->get('/notifications');

        $result->assertStatus(200);
        $result->assertSee('Page 1 of 2');
        $result->assertSee('Next');
    }

    public function testIndexOnlyShowsTheCallersOwnNotifications(): void
    {
        $userA = $this->seedWebUser(['email' => 'view-notif-a@example.com', 'zone' => 'client']);
        $userB = $this->seedWebUser(['email' => 'view-notif-b@example.com', 'zone' => 'client']);
        $this->seedRow(['user_id' => $userA->id, 'message' => 'AFirstMsg']);
        $this->seedRow(['user_id' => $userA->id, 'message' => 'ASecondMsg']);
        $this->seedRow(['user_id' => $userB->id, 'message' => 'BOnlyMsg']);

        $this->loginAs($userA);
        $result = $this->withSession()->get('/notifications');

        $result->assertStatus(200);
        // Rows are fed to Alpine as a JSON blob inside an `esc(..., 'attr')`
        // attribute value (no per-row server-rendered text) — the escaper
        // encodes every non-alphanumeric char, so use markers without spaces
        // or punctuation and check the raw attribute value directly.
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('AFirstMsg', $body);
        $this->assertStringContainsString('ASecondMsg', $body);
        $this->assertStringNotContainsString('BOnlyMsg', $body);
    }

    /**
     * Rows are fed to Alpine via `esc(json_encode($itemsForJs), 'attr')` and
     * bound with `x-text` (never `x-html`) — so the raw script tag must
     * never appear unescaped, the HTML-entity-escaped form must appear
     * instead, and the page must not use `x-html` anywhere.
     */
    public function testMessageWithScriptTagIsEscapedAndBoundViaXTextNeverXHtml(): void
    {
        $user = $this->seedWebUser(['email' => 'view-notif-xss@example.com', 'zone' => 'client']);
        $this->seedRow(['user_id' => $user->id, 'message' => self::XSS_SCRIPT]);
        $this->loginAs($user);

        $result = $this->withSession()->get('/notifications');

        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertStringNotContainsString(self::XSS_SCRIPT, $body);
        $this->assertStringContainsString('&lt;script&gt;alert&#x28;1&#x29;', $body);
        $this->assertStringContainsString('x-text="item.message"', $body);
        $this->assertStringNotContainsString('x-html', $body);
    }

    public function testAuthenticatedPageIncludesNotificationBellAndSidebarLink(): void
    {
        $user = $this->seedWebUser(['email' => 'view-notif-bell@example.com', 'zone' => 'client']);
        $this->loginAs($user);

        $result = $this->withSession()->get('/dashboard');

        $result->assertStatus(200);
        $body = (string) $result->response()->getBody();
        $this->assertStringContainsString('x-data="notificationBell()"', $body);
        $this->assertStringContainsString('href="/notifications"', $body);
    }

    public function testGuestIsRedirectedToLoginFromNotificationsIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/notifications');

        $result->assertRedirectTo('/login');
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
}
