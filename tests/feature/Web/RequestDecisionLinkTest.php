<?php

use App\Models\LeaveRequestModel;
use App\Models\SiteVariableModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Mail;
use Tests\Support\Web\WebAuthFixture;

/**
 * The one-click approve / reject link in the administrator's email.
 *
 * The token in the URL *is* the credential — nobody is signed in — so the cases
 * that matter are the ones where a link should stop working: after it has been
 * used, after it expires, after the employee withdraws, and after somebody else
 * has already decided. And the one that matters most: **opening the link must
 * decide nothing**, because mail clients fetch URLs on their own.
 *
 * @internal
 */
final class RequestDecisionLinkTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;

    protected function setUp(): void
    {
        parent::setUp();
        Time::setTestNow(Time::parse('2026-07-22 02:00:00', 'UTC'));

        $config                 = new Mail();
        $config->sending_enable = 'no';
        $config->from_email     = 'office@example.com';
        Factories::injectMock('config', 'Mail', $config);
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
        session()->destroy();
    }

    private function setSetting(string $name, string $value): void
    {
        $model = model(SiteVariableModel::class);
        $row   = $model->findByName($name);

        $row === null
            ? $model->insert(['name' => $name, 'value' => $value, 'type' => 'string'])
            : $model->update($row->id, ['value' => $value]);
    }

    /**
     * Submit a request as an employee and return [request row, emailed token].
     *
     * The token is read out of the message that was actually sent, not minted on
     * the side — that is the whole point: the link in the inbox has to work.
     *
     * @return array{0: object, 1: string}
     */
    private function submitAndCaptureLink(): array
    {
        $user = $this->seedWebUser(['email' => 'walker@example.com', 'name' => 'Walker']);
        $this->loginAs($user);

        $this->withSession()->post('/requests/create', $this->withCsrf([
            'type'       => LeaveRequestModel::TYPE_LEAVE,
            'leave_kind' => 'annual',
            'start_date' => '2026-07-29',
            'end_date'   => '2026-07-29',
            'reason'     => 'Family matters',
        ]))->assertStatus(200);

        session()->destroy();   // the administrator opening a mail is not signed in

        $row  = model(LeaveRequestModel::class)->where('user_id', $user->id)->first();
        $mail = model(App\Models\EmailModel::class)->where('name', 'requests.submitted')->first();

        $this->assertNotNull($mail, 'the submission should have mailed the administrator');

        // `esc($url, 'attr')` encodes slashes as entities; a mail client decodes
        // them before following the link, so the test reads what the reader sees.
        $body = html_entity_decode((string) $mail->content);
        $this->assertMatchesRegularExpression('#request-decision/([^/"]+)/approve#', $body);

        preg_match('#request-decision/([^/"]+)/approve#', $body, $m);

        return [$row, $m[1]];
    }

    // ------------------------------------------------------------- the email

    public function testTheAdminEmailCarriesBothButtons(): void
    {
        [, $token] = $this->submitAndCaptureLink();

        $body = html_entity_decode((string) model(App\Models\EmailModel::class)->where('name', 'requests.submitted')->first()->content);

        $this->assertStringContainsString('request-decision/' . $token . '/approve', $body);
        $this->assertStringContainsString('request-decision/' . $token . '/reject', $body);
        $this->assertStringContainsString('Approve', $body);
        $this->assertStringContainsString('Reject', $body);
    }

    /**
     * The token is unique per request and stored only as a hash — a database
     * backup must not be replayable into an approval.
     */
    public function testTheTokenIsNeverStoredInPlaintextAndDiffersPerRequest(): void
    {
        [$first, $tokenOne] = $this->submitAndCaptureLink();

        $stored = model(LeaveRequestModel::class)->findRequest((int) $first->id);
        $this->assertNotNull($stored->decision_token_hash);
        $this->assertSame(hash('sha256', $tokenOne), $stored->decision_token_hash);
        $this->assertStringNotContainsString($tokenOne, (string) $stored->decision_token_hash);

        $second = $this->seedWebUser(['email' => 'other@example.com', 'name' => 'Other']);
        $this->loginAs($second);
        $this->withSession()->post('/requests/create', $this->withCsrf([
            'type'       => LeaveRequestModel::TYPE_LEAVE,
            'leave_kind' => 'annual',
            'start_date' => '2026-08-05',
            'reason'     => 'Trip',
        ]))->assertStatus(200);

        $other = model(LeaveRequestModel::class)->where('user_id', $second->id)->first();
        $this->assertNotSame($stored->decision_token_hash, $other->decision_token_hash);
    }

    // ------------------------------------------------------- opening the link

    public function testOpeningTheLinkOnlyAsksAndDecidesNothing(): void
    {
        [$row, $token] = $this->submitAndCaptureLink();

        $result = $this->get('/request-decision/' . $token . '/approve');

        $result->assertStatus(200);
        $result->assertSee('Approve this request?');
        $result->assertSee('Walker');
        $result->assertSee('Family matters');

        // A mail client following the link must not have approved anything.
        $this->seeInDatabase('leave_requests', ['id' => $row->id, 'status' => LeaveRequestModel::STATUS_PENDING]);
    }

    public function testTheRejectButtonLeadsWithRejecting(): void
    {
        [, $token] = $this->submitAndCaptureLink();

        $this->get('/request-decision/' . $token . '/reject')->assertSee('Reject this request?');
    }

    public function testConfirmingApprovesAndTellsTheEmployee(): void
    {
        [$row, $token] = $this->submitAndCaptureLink();

        $result = $this->withSession()->post('/request-decision/' . $token, $this->withCsrf([
            'action'      => 'approve',
            'review_note' => 'Enjoy the day off.',
        ]));

        $result->assertStatus(200);
        $result->assertSee('The request is approved.');

        $this->seeInDatabase('leave_requests', [
            'id'          => $row->id,
            'status'      => LeaveRequestModel::STATUS_APPROVED,
            'review_note' => 'Enjoy the day off.',
        ]);
        $this->seeInDatabase('notifications', ['user_id' => $row->user_id]);
        $this->assertNotNull(
            model(App\Models\EmailModel::class)->where('name', 'requests.approved')->first(),
            'the employee should have been mailed the decision',
        );
    }

    public function testConfirmingRejectionWorksThroughTheSameLink(): void
    {
        [$row, $token] = $this->submitAndCaptureLink();

        $this->withSession()->post('/request-decision/' . $token, $this->withCsrf([
            'action'      => 'reject',
            'review_note' => 'We need cover that day.',
        ]))->assertStatus(200);

        $this->seeInDatabase('leave_requests', ['id' => $row->id, 'status' => LeaveRequestModel::STATUS_REJECTED]);
    }

    // ----------------------------------------------------- when it must fail

    public function testTheLinkIsSpentAfterOneDecision(): void
    {
        [$row, $token] = $this->submitAndCaptureLink();

        $this->withSession()->post('/request-decision/' . $token, $this->withCsrf(['action' => 'approve']))
            ->assertStatus(200);

        $this->get('/request-decision/' . $token . '/approve')->assertStatus(410);
        $this->withSession()->post('/request-decision/' . $token, $this->withCsrf(['action' => 'reject']))
            ->assertStatus(410);

        // The second attempt changed nothing.
        $this->seeInDatabase('leave_requests', ['id' => $row->id, 'status' => LeaveRequestModel::STATUS_APPROVED]);
    }

    public function testAnExpiredLinkIsRefused(): void
    {
        [, $token] = $this->submitAndCaptureLink();

        Time::setTestNow(Time::parse('2026-09-30 02:00:00', 'UTC'));

        $this->get('/request-decision/' . $token . '/approve')->assertStatus(410);
    }

    public function testWithdrawingRetiresTheLinkBeforeAnybodyClicksIt(): void
    {
        [$row, $token] = $this->submitAndCaptureLink();

        $this->loginAs(model(App\Models\UserModel::class)->findUser((int) $row->user_id));
        $this->withSession()->post('/requests/cancel/' . $row->id, $this->withCsrf([]))->assertStatus(200);
        session()->destroy();

        $this->get('/request-decision/' . $token . '/approve')->assertStatus(410);
    }

    public function testAnInventedTokenIsRefusedWithoutRevealingAnything(): void
    {
        $this->get('/request-decision/not-a-real-token/approve')->assertStatus(410);
        $this->get('/request-decision/abc.' . str_repeat('0', 32) . '/approve')->assertStatus(410);
    }

    /**
     * One token must not decide a different request.
     */
    public function testATokenOnlyDecidesItsOwnRequest(): void
    {
        [$first, $tokenOne] = $this->submitAndCaptureLink();

        $second = $this->seedWebUser(['email' => 'other@example.com']);
        $this->loginAs($second);
        $this->withSession()->post('/requests/create', $this->withCsrf([
            'type'       => LeaveRequestModel::TYPE_LEAVE,
            'leave_kind' => 'annual',
            'start_date' => '2026-08-05',
            'reason'     => 'Trip',
        ]))->assertStatus(200);
        session()->destroy();

        $other = model(LeaveRequestModel::class)->where('user_id', $second->id)->first();

        $this->withSession()->post('/request-decision/' . $tokenOne, $this->withCsrf(['action' => 'approve']))
            ->assertStatus(200);

        $this->seeInDatabase('leave_requests', ['id' => $first->id, 'status' => LeaveRequestModel::STATUS_APPROVED]);
        $this->seeInDatabase('leave_requests', ['id' => $other->id, 'status' => LeaveRequestModel::STATUS_PENDING]);
    }

    /**
     * Decided in the app while the mail sat in an inbox: the link must say so
     * rather than overwrite the decision.
     */
    public function testALinkForARequestDecidedInTheAppIsDead(): void
    {
        [$row, $token] = $this->submitAndCaptureLink();

        $this->loginAs($this->seedWebUser(['email' => 'boss@example.com', 'zone' => 'admin']));
        $this->withSession()->post('/admin/requests/approve/' . $row->id, $this->withCsrf([]))->assertStatus(200);
        session()->destroy();

        $this->get('/request-decision/' . $token . '/reject')->assertStatus(410);
        $this->seeInDatabase('leave_requests', ['id' => $row->id, 'status' => LeaveRequestModel::STATUS_APPROVED]);
    }

    // --------------------------------------------------------- who decided it

    public function testTheDecisionIsAttributedToTheAdministratorAccountWhenThereIsOne(): void
    {
        $admin = $this->seedWebUser(['email' => 'boss@example.com', 'zone' => 'admin']);
        $this->setSetting('mail_notify_admin_recipient', 'boss@example.com');

        [$row, $token] = $this->submitAndCaptureLink();

        $this->withSession()->post('/request-decision/' . $token, $this->withCsrf(['action' => 'approve']))
            ->assertStatus(200);

        $this->seeInDatabase('leave_requests', ['id' => $row->id, 'reviewed_by' => $admin->id]);
    }

    /**
     * With several administrators configured we cannot know which one clicked, so
     * the first address that belongs to an account is the attribution. Everyone
     * shares one token — whoever acts first decides.
     */
    public function testSeveralAdminsShareOneLinkAndTheFirstKnownAccountIsCredited(): void
    {
        $this->seedWebUser(['email' => 'nobody-account@example.com', 'zone' => 'admin', 'name' => 'Ghost']);
        $hr = $this->seedWebUser(['email' => 'hr@example.com', 'zone' => 'admin', 'name' => 'HR']);
        $this->setSetting('mail_notify_admin_recipient', 'unknown@example.com, hr@example.com');

        [$row, $token] = $this->submitAndCaptureLink();

        // One event, two copies, and the same link in both.
        $mails = model(App\Models\EmailModel::class)->where('name', 'requests.submitted')->findAll();
        $this->assertCount(2, $mails);

        foreach ($mails as $mail) {
            $this->assertStringContainsString(
                'request-decision/' . $token,
                html_entity_decode((string) $mail->content),
                'every administrator gets the same working link',
            );
        }

        $this->withSession()->post('/request-decision/' . $token, $this->withCsrf(['action' => 'approve']))
            ->assertStatus(200);

        $this->seeInDatabase('leave_requests', ['id' => $row->id, 'reviewed_by' => $hr->id]);
    }

    public function testAnAddressBelongingToNobodyRecordsNoReviewerRatherThanInventingOne(): void
    {
        $this->setSetting('mail_notify_admin_recipient', 'nobody@example.com');

        [$row, $token] = $this->submitAndCaptureLink();

        $this->withSession()->post('/request-decision/' . $token, $this->withCsrf(['action' => 'approve']))
            ->assertStatus(200);

        $decided = model(LeaveRequestModel::class)->findRequest((int) $row->id);
        $this->assertSame(LeaveRequestModel::STATUS_APPROVED, $decided->status);
        $this->assertNull($decided->reviewed_by);
    }

    // ------------------------------------------------------------- the gates

    public function testTheSiteGateDoesNotBlockTheLink(): void
    {
        $filters = config('Filters');
        $this->assertContains(
            'request-decision/*',
            $filters->globals['before']['basicauth']['except'],
            'the emailed link must skip the basic-auth site gate — there is nobody to hand a second password to',
        );
    }

    public function testTheDecisionPageDoesNotRequireASession(): void
    {
        [, $token] = $this->submitAndCaptureLink();

        // No login, no session at all: still a 200, not a redirect to /login.
        $this->get('/request-decision/' . $token . '/approve')->assertStatus(200);
    }
}
