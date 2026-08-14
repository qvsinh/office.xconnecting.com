<?php

use App\Libraries\RequestNotifier;
use App\Models\EmailModel;
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
 * Mail for leave / late-arrival requests: the administrator hears about a request
 * arriving or being withdrawn, the employee hears the decision.
 *
 * Sending is switched **off** in these tests (`mail.sending_enable = no`), which
 * is the honest way to test this: `Mailer` still records every attempt in
 * `emails`, so the assertions are about what the app decided to send and to whom —
 * not about whether SES was reachable from a test runner.
 *
 * @internal
 */
final class RequestMailTest extends CIUnitTestCase
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
        // A Wednesday, well before the dates requested below, so no period lock
        // or past-date rule interferes.
        Time::setTestNow(Time::parse('2026-07-22 02:00:00', 'UTC'));
        Factories::injectMock('config', 'Mail', $this->mailConfig());
    }

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
        session()->destroy();
    }

    /**
     * Mail switched off at the transport, on in the app: every attempt is logged
     * as pending instead of leaving the test runner.
     */
    private function mailConfig(): Mail
    {
        $config                 = new Mail();
        $config->sending_enable = 'no';
        $config->aws            = ['access_key_id' => '', 'secret_access_key' => '', 'region' => 'us-west-2'];
        $config->from_email     = 'office@example.com';

        return $config;
    }

    private function setSetting(string $name, string $value): void
    {
        $model = model(SiteVariableModel::class);
        $row   = $model->findByName($name);

        if ($row === null) {
            $model->insert(['name' => $name, 'value' => $value, 'type' => 'string']);

            return;
        }

        $model->update($row->id, ['value' => $value]);
    }

    /**
     * @return list<object>
     */
    private function logged(?string $label = null): array
    {
        $model = model(EmailModel::class);

        if ($label !== null) {
            $model = $model->where('name', $label);
        }

        return $model->orderBy('created_at', 'ASC')->orderBy('id', 'ASC')->findAll();
    }

    private function submitLeave(array $overrides = []): void
    {
        $this->withSession()->post('/requests/create', $this->withCsrf(array_merge([
            'type'       => LeaveRequestModel::TYPE_LEAVE,
            'leave_kind' => 'annual',
            'start_date' => '2026-07-29',
            'end_date'   => '2026-07-29',
            'reason'     => 'Family matters',
        ], $overrides)))->assertStatus(200);
    }

    // ------------------------------------------------------------- submission

    public function testSubmittingLeaveMailsTheAdministrator(): void
    {
        $this->setSetting(RequestNotifier::SETTING_RECIPIENT, 'boss@example.com');
        $this->loginAs($this->seedWebUser(['email' => 'walker@example.com', 'name' => 'Walker']));

        $this->submitLeave();

        $logged = $this->logged('requests.submitted');
        $this->assertCount(1, $logged, 'The administrator should be told once.');
        $this->assertSame('boss@example.com', $logged[0]->to_email);
        $this->assertStringContainsString('Walker', (string) $logged[0]->subject);

        $body = (string) $logged[0]->content;
        $this->assertStringContainsString('Annual leave', $body);
        $this->assertStringContainsString('2026-07-29', $body);
        $this->assertStringContainsString('Family matters', $body);
        // Configuration names belong on the settings screen, not in an inbox.
        $this->assertStringNotContainsString('mail_notify_', $body);
    }

    public function testSubmittingALateArrivalMailsTheAdministratorToo(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'late@example.com', 'name' => 'Late Riser']));

        $this->withSession()->post('/requests/create', $this->withCsrf([
            'type'          => LeaveRequestModel::TYPE_LATE,
            'start_date'    => '2026-07-29',
            'expected_time' => '09:30',
            'reason'        => 'Dentist',
        ]))->assertStatus(200);

        $logged = $this->logged('requests.submitted');
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('Late arrival', (string) $logged[0]->content);
    }

    public function testARefusedSubmissionMailsNobody(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'walker@example.com']));

        // No leave_kind: refused at the controller boundary.
        $this->withSession()->post('/requests/create', $this->withCsrf([
            'type'       => LeaveRequestModel::TYPE_LEAVE,
            'start_date' => '2026-07-29',
            'reason'     => 'No kind given',
        ]))->assertStatus(400);

        $this->assertSame([], $this->logged());
    }

    public function testWithdrawingTellsTheAdministratorItIsNoLongerTheirProblem(): void
    {
        $user = $this->seedWebUser(['email' => 'walker@example.com', 'name' => 'Walker']);
        $this->loginAs($user);
        $this->submitLeave();

        $request = model(LeaveRequestModel::class)->where('user_id', $user->id)->first();
        $this->withSession()->post('/requests/cancel/' . $request->id, $this->withCsrf([]))->assertStatus(200);

        $logged = $this->logged('requests.withdrawn');
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('withdrew', (string) $logged[0]->subject);
        $this->assertStringContainsString('Nothing to do', (string) $logged[0]->content);
    }

    // --------------------------------------------------------------- decision

    public function testApprovingMailsTheEmployeeWithTheManagersNote(): void
    {
        $employee = $this->seedWebUser(['email' => 'walker@example.com', 'name' => 'Walker']);
        $this->loginAs($employee);
        $this->submitLeave();
        $request = model(LeaveRequestModel::class)->where('user_id', $employee->id)->first();

        $this->loginAs($this->seedWebUser(['email' => 'boss@example.com', 'zone' => 'admin']));
        $this->withSession()->post('/admin/requests/approve/' . $request->id, $this->withCsrf([
            'review_note' => 'Enjoy the day off.',
        ]))->assertStatus(200);

        $logged = $this->logged('requests.approved');
        $this->assertCount(1, $logged);
        // Addressed to the person who asked, never to the administrator.
        $this->assertSame('walker@example.com', $logged[0]->to_email);

        $body = (string) $logged[0]->content;
        $this->assertStringContainsString('was approved', $body);
        $this->assertStringContainsString('Enjoy the day off.', $body);
    }

    public function testRejectingMailsTheEmployeeAndSaysWhy(): void
    {
        $employee = $this->seedWebUser(['email' => 'walker@example.com', 'name' => 'Walker']);
        $this->loginAs($employee);
        $this->submitLeave();
        $request = model(LeaveRequestModel::class)->where('user_id', $employee->id)->first();

        $this->loginAs($this->seedWebUser(['email' => 'boss@example.com', 'zone' => 'admin']));
        $this->withSession()->post('/admin/requests/reject/' . $request->id, $this->withCsrf([
            'review_note' => 'We need cover that day.',
        ]))->assertStatus(200);

        $logged = $this->logged('requests.rejected');
        $this->assertCount(1, $logged);
        $this->assertSame('walker@example.com', $logged[0]->to_email);
        $this->assertStringContainsString('We need cover that day.', (string) $logged[0]->content);
    }

    /**
     * The in-app notification is not replaced by the mail — one reaches them on
     * the site, the other reaches them off it.
     */
    public function testTheDecisionStillRaisesTheInAppNotification(): void
    {
        $employee = $this->seedWebUser(['email' => 'walker@example.com', 'name' => 'Walker']);
        $this->loginAs($employee);
        $this->submitLeave();
        $request = model(LeaveRequestModel::class)->where('user_id', $employee->id)->first();

        $this->loginAs($this->seedWebUser(['email' => 'boss@example.com', 'zone' => 'admin']));
        $this->withSession()->post('/admin/requests/approve/' . $request->id, $this->withCsrf([]))->assertStatus(200);

        $this->seeInDatabase('notifications', ['user_id' => $employee->id]);
        $this->assertCount(1, $this->logged('requests.approved'));
    }

    /**
     * A second manager losing the race must not send a second decision mail.
     */
    public function testADecisionThatLosesTheRaceMailsNothing(): void
    {
        $employee = $this->seedWebUser(['email' => 'walker@example.com']);
        $this->loginAs($employee);
        $this->submitLeave();
        $request = model(LeaveRequestModel::class)->where('user_id', $employee->id)->first();

        $this->loginAs($this->seedWebUser(['email' => 'boss@example.com', 'zone' => 'admin']));
        $this->withSession()->post('/admin/requests/approve/' . $request->id, $this->withCsrf([]))->assertStatus(200);
        $this->withSession()->post('/admin/requests/reject/' . $request->id, $this->withCsrf([]))->assertStatus(409);

        $this->assertCount(1, $this->logged('requests.approved'));
        $this->assertSame([], $this->logged('requests.rejected'));
    }

    // ----------------------------------------------------------- the switches

    public function testTheSwitchSilencesBothDirections(): void
    {
        $this->setSetting(RequestNotifier::SETTING_ENABLED, '0');

        $employee = $this->seedWebUser(['email' => 'walker@example.com']);
        $this->loginAs($employee);
        $this->submitLeave();

        $this->assertSame([], $this->logged(), 'Nothing on submission.');

        $request = model(LeaveRequestModel::class)->where('user_id', $employee->id)->first();
        $this->loginAs($this->seedWebUser(['email' => 'boss@example.com', 'zone' => 'admin']));
        $this->withSession()->post('/admin/requests/approve/' . $request->id, $this->withCsrf([]))->assertStatus(200);

        $this->assertSame([], $this->logged(), 'Nothing on the decision either.');
        // The decision itself still happened — mail is the courtesy, not the fact.
        $this->seeInDatabase('leave_requests', ['id' => $request->id, 'status' => LeaveRequestModel::STATUS_APPROVED]);
    }

    public function testTheAdminAddressIsTheSharedOneAndFallsBackWhenBlank(): void
    {
        $this->setSetting(RequestNotifier::SETTING_RECIPIENT, '   ');

        $this->assertSame('mail_notify_admin_recipient', RequestNotifier::SETTING_RECIPIENT);
        $this->assertSame(RequestNotifier::DEFAULT_RECIPIENT, (new RequestNotifier())->recipient());
    }

    /**
     * Mail must never be able to turn a valid request into an error. A recipient
     * SES would refuse is the cheapest way to prove the failure stays contained.
     */
    public function testABrokenRecipientStillLetsTheRequestThrough(): void
    {
        $this->setSetting(RequestNotifier::SETTING_RECIPIENT, 'not-an-address');

        $user = $this->seedWebUser(['email' => 'walker@example.com']);
        $this->loginAs($user);
        $this->submitLeave();

        $this->seeInDatabase('leave_requests', ['user_id' => $user->id, 'status' => LeaveRequestModel::STATUS_PENDING]);

        $logged = $this->logged('requests.submitted');
        $this->assertCount(1, $logged, 'The attempt is recorded rather than vanishing.');
        $this->assertSame(2, (int) $logged[0]->status, 'Logged as failed, not sent.');
    }
}
