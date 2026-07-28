<?php

use App\Models\EmailModel;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Admin sent-email log (`Admin\Emails`), exercised through real routing +
 * filters (`authFilter`/`adminFilter`): read-only list + view-detail, admin
 * gating. Rows are seeded directly via `EmailModel` — the send pipeline that
 * would normally populate this table doesn't exist yet (out of scope, see
 * kanban/processing/20260714-admin-emails.md §7). `index()` renders a view
 * that doesn't exist yet (`frontend-coder` builds it next) — this suite only
 * exercises routes that return JSON, plus gating on `index()`.
 *
 * @internal
 */
final class AdminEmailsTest extends CIUnitTestCase
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

    private function seedEmail(array $overrides = []): object
    {
        $model = model(EmailModel::class);
        $row   = array_merge([
            'id'         => bin2hex(random_bytes(8)),
            'subject'    => 'Verify your email',
            'content'    => '<p>Hello</p>',
            'from_email' => 'noreply@example.com',
            'to_email'   => 'user@example.com',
            'status'     => 1,
            'name'       => 'signup_verify_email',
        ], $overrides);
        $model->insert($row);

        return $model->findEmail($row['id']);
    }

    public function testShowReturnsEmailFieldsForKnownId(): void
    {
        $admin = $this->seedWebUser(['email' => 'viewer@example.com', 'zone' => 'admin']);
        $email = $this->seedEmail(['id' => 'row-view', 'to_email' => 'someone@example.com']);
        $this->loginAs($admin);

        $show = $this->withSession()->get('/admin/emails/show/' . $email->id);

        $show->assertStatus(200);
        $json = json_decode($show->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertSame('row-view', $json['email']['id']);
        $this->assertSame('someone@example.com', $json['email']['to_email']);
        $this->assertSame('Verify your email', $json['email']['subject']);
    }

    public function testShowReturnsNotFoundForUnknownId(): void
    {
        $admin = $this->seedWebUser(['email' => 'viewer2@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $show = $this->withSession()->get('/admin/emails/show/does-not-exist');

        $show->assertStatus(404);
    }

    public function testCreateRouteDoesNotExistForEmails(): void
    {
        $admin = $this->seedWebUser(['email' => 'writer-create@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        $this->expectException(PageNotFoundException::class);
        $this->withSession()->post('/admin/emails/create', $this->withCsrf(['subject' => 'nope']));
    }

    public function testUpdateRouteDoesNotExistForEmails(): void
    {
        $admin = $this->seedWebUser(['email' => 'writer-update@example.com', 'zone' => 'admin']);
        $email = $this->seedEmail(['id' => 'row-immutable-update']);
        $this->loginAs($admin);

        $this->expectException(PageNotFoundException::class);
        $this->withSession()->post('/admin/emails/update', $this->withCsrf(['id' => $email->id]));
    }

    public function testDeleteRouteDoesNotExistForEmails(): void
    {
        $admin = $this->seedWebUser(['email' => 'writer-delete@example.com', 'zone' => 'admin']);
        $email = $this->seedEmail(['id' => 'row-immutable-delete']);
        $this->loginAs($admin);

        $this->expectException(PageNotFoundException::class);
        $this->withSession()->post('/admin/emails/delete', $this->withCsrf(['id' => $email->id]));
    }

    public function testNonAdminClientSessionIsRejectedByAdminGate(): void
    {
        $client = $this->seedWebUser(['email' => 'client-gate@example.com', 'zone' => 'user']);
        $this->loginAs($client);

        $index = $this->withSession()->get('/admin/emails');
        $index->assertRedirectTo('/dashboard');
    }

    public function testGuestIsRedirectedToLoginFromAdminEmailsIndex(): void
    {
        $this->withSession(['basicAuthAuthenticated' => 1]);

        $result = $this->get('/admin/emails');

        $result->assertRedirectTo('/login');
    }
}
