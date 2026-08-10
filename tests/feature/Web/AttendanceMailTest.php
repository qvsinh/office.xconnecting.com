<?php

use App\Libraries\AttendanceNotifier;
use App\Libraries\Mailer;
use App\Models\AccessLogModel;
use App\Models\AttendanceDayModel;
use App\Models\EmailModel;
use App\Models\SiteVariableModel;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Mail;
use Tests\Support\Web\WebAuthFixture;

/**
 * Attendance notification mail: who it goes to, when it is suppressed, and — the
 * one that matters most — that it can never break a check-in.
 *
 * Nothing here talks to SES. The transport is exercised through `Mailer` with a
 * config that is switched off or incomplete, which is exactly the path a
 * misconfigured server takes; a test that needed real credentials would be
 * skipped forever and prove nothing.
 *
 * @internal
 */
final class AttendanceMailTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private function setSetting(string $name, string $value): void
    {
        model(SiteVariableModel::class)->setValue($name, $value);
    }

    /**
     * @return list<object>
     */
    private function logged(): array
    {
        return model(EmailModel::class)->orderBy('created_at', 'ASC')->findAll();
    }

    /**
     * @return list<object>
     */
    private function loggedFor(string $label): array
    {
        return array_values(array_filter($this->logged(), static fn (object $row): bool => $row->name === $label));
    }

    private function mailConfig(bool $enabled, bool $configured = true): Mail
    {
        $config                 = new Mail();
        $config->sending_enable = $enabled ? 'yes' : 'no';
        $config->sender         = ['name' => 'Office XCN', 'email' => 'no-reply@example.com'];
        $config->aws            = $configured
            ? ['access_key_id' => 'AKIATEST000000000000', 'secret_access_key' => 'secret', 'region' => 'ap-northeast-1']
            : ['access_key_id' => '', 'secret_access_key' => '', 'region' => ''];

        return $config;
    }

    // ------------------------------------------------------- the check-in path

    /**
     * The whole point: an employee checks in, the administrator gets told.
     */
    public function testCheckingInQueuesANotificationToTheConfiguredRecipient(): void
    {
        // Injected rather than inherited from `.env`: an earlier version read
        // whatever the developer had configured, so it passed while sending was off
        // and failed the day somebody switched SES on. The status under test is
        // "sending is off", which the test has to state.
        Factories::injectMock('config', 'Mail', $this->mailConfig(false));

        $this->setSetting(AttendanceNotifier::SETTING_RECIPIENT, 'boss@example.com');
        $this->loginAs($this->seedWebUser(['email' => 'arriving@example.com', 'name' => 'Arriving Person']));

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);

        // Two messages now: the admin's report and the employee's receipt.
        $this->assertCount(2, $this->logged());

        $admin = $this->loggedFor('attendance.' . AccessLogModel::EVENT_CHECKIN);
        $this->assertCount(1, $admin, 'Exactly one admin notification.');

        $mail = $admin[0];
        $this->assertSame('boss@example.com', $mail->to_email);
        $this->assertSame('attendance.' . AccessLogModel::EVENT_CHECKIN, $mail->name);
        $this->assertStringContainsString('Arriving Person', $mail->subject);
        $this->assertStringContainsString('checked in', $mail->subject);
        // Sending is off, so it is recorded as pending, not failed.
        $this->assertSame(Mailer::STATUS_PENDING, (int) $mail->status);
    }

    public function testCheckingOutSendsItsOwnNotification(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'leaving@example.com', 'name' => 'Leaving Person']));

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);
        $this->withSession()->post('/attendance/checkout', $this->withCsrf([]))->assertStatus(200);

        // Compared as a set: both rows land in the same second, and `emails.id` is
        // random, so there is no column that orders them deterministically. What
        // matters is that each event produced its own correctly labelled mail.
        $names = array_map(static fn (object $row): string => $row->name, $this->logged());
        sort($names);

        // Four rows: an admin report and an employee receipt for each event.
        $this->assertSame([
            'attendance.' . AccessLogModel::EVENT_CHECKIN,
            'attendance.' . AccessLogModel::EVENT_CHECKIN . '.employee',
            'attendance.' . AccessLogModel::EVENT_CHECKOUT,
            'attendance.' . AccessLogModel::EVENT_CHECKOUT . '.employee',
        ], $names);

        $subjects = array_map(static fn (object $row): string => $row->subject, $this->logged());
        $this->assertSame(2, count(array_filter($subjects, static fn (string $s): bool => str_contains($s, 'checked in'))));
        $this->assertSame(2, count(array_filter($subjects, static fn (string $s): bool => str_contains($s, 'checked out'))));
    }

    /**
     * A rejected check-in has nothing to announce.
     */
    public function testARejectedCheckInSendsNothing(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'double@example.com']));

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);
        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(409);

        // One event's worth (admin + employee), not two events'.
        $this->assertCount(2, $this->logged(), 'The refused second attempt must not notify.');
        $this->assertCount(1, $this->loggedFor('attendance.' . AccessLogModel::EVENT_CHECKIN));
    }

    // ------------------------------------------------------------- the switch

    public function testTheAdminCanTurnNotificationsOff(): void
    {
        $this->setSetting(AttendanceNotifier::SETTING_ENABLED, '0');
        $this->loginAs($this->seedWebUser(['email' => 'quiet@example.com']));

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);

        $this->assertSame([], $this->logged(), 'With the setting off, nothing should even be recorded.');
    }

    /**
     * @dataProvider switchProvider
     */
    public function testTheSwitchUnderstandsHowPeopleWriteIt(string $value, bool $expected): void
    {
        $this->setSetting(AttendanceNotifier::SETTING_ENABLED, $value);

        $this->assertSame($expected, (new AttendanceNotifier())->enabled(), "mail_notify_attendance = {$value}");
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function switchProvider(): array
    {
        return [
            '1'     => ['1', true],
            'yes'   => ['yes', true],
            'true'  => ['true', true],
            '0'     => ['0', false],
            'no'    => ['no', false],
            'empty' => ['', false],
        ];
    }

    /**
     * An emptied recipient must not mean "send to nobody silently" — it falls back
     * to the built-in address so the mail still lands somewhere real.
     */
    public function testAnEmptyRecipientFallsBackRatherThanVanishing(): void
    {
        $this->setSetting(AttendanceNotifier::SETTING_RECIPIENT, '   ');

        $this->assertSame(AttendanceNotifier::DEFAULT_RECIPIENT, (new AttendanceNotifier())->recipient());
    }

    // -------------------------------------------------------------- robustness

    /**
     * The one that matters. An employee tapping "Check in" must succeed even if
     * the mail layer is completely broken — here, the log table is gone, so both
     * the send and its own logging fail.
     */
    public function testCheckingInStillSucceedsWhenTheMailLayerIsBroken(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'resilient@example.com']));

        $this->db->query('DROP TABLE emails');

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);

        // ...and the attendance row is really there, not half-written.
        $this->assertNotNull(
            model(AttendanceDayModel::class)->todayFor((int) model(App\Models\UserModel::class)->findActiveByEmail('resilient@example.com')->id),
        );
    }

    // ------------------------------------------------------------ the transport

    public function testSendingOffIsRecordedAsPendingNotFailed(): void
    {
        $status = (new Mailer($this->mailConfig(false)))->send('someone@example.com', 'Subject', '<p>Body</p>', 'probe');

        $this->assertSame(Mailer::STATUS_PENDING, $status);
        $this->assertSame(Mailer::STATUS_PENDING, (int) $this->logged()[0]->status);
        $this->assertStringContainsString('sending_enable', $this->logged()[0]->error_message);
    }

    /**
     * Switched on but with no credentials is a real misconfiguration, and must
     * read as failed so it stands out in the admin list.
     */
    public function testEnabledButUnconfiguredIsRecordedAsFailed(): void
    {
        $status = (new Mailer($this->mailConfig(true, false)))->send('someone@example.com', 'Subject', '<p>Body</p>', 'probe');

        $this->assertSame(Mailer::STATUS_FAILED, $status);
        $this->assertStringContainsString('not configured', $this->logged()[0]->error_message);
    }

    /**
     * @dataProvider badRecipientProvider
     */
    public function testAnUnusableRecipientFailsBeforeReachingSes(string $to): void
    {
        $status = (new Mailer($this->mailConfig(true)))->send($to, 'Subject', '<p>Body</p>', 'probe');

        $this->assertSame(Mailer::STATUS_FAILED, $status);
        $this->assertStringContainsString('recipient', $this->logged()[0]->error_message);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badRecipientProvider(): array
    {
        return [
            'empty'       => [''],
            'whitespace'  => ['   '],
            'not an email'=> ['not-an-email'],
        ];
    }

    // ------------------------------------------------------------- the template

    /**
     * The body is HTML built from database values, so it has to be escaped —
     * a mail client renders injected markup just like a browser would.
     */
    public function testTheEmployeeNameIsEscapedInTheBody(): void
    {
        $this->loginAs($this->seedWebUser([
            'email' => 'xss@example.com',
            'name'  => '<script>alert(1)</script>Bobby',
        ]));

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);

        $body = (string) $this->logged()[0]->content;

        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;', $body);
    }

    // ------------------------------------------------- who actually gets mail

    /**
     * Each audience gets its own message, addressed to itself.
     *
     * The admin's copy goes to the configured address and never to the employee —
     * the employee's address belongs in its body as information about who checked
     * in. The employee's copy goes to the employee and nowhere else.
     */
    public function testEachAudienceIsAddressedSeparately(): void
    {
        Factories::injectMock('config', 'Mail', $this->mailConfig(false));
        $this->setSetting(AttendanceNotifier::SETTING_RECIPIENT, 'boss@example.com');

        $this->loginAs($this->seedWebUser(['email' => 'walker@example.com', 'name' => 'Walker']));
        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);

        $admin    = $this->loggedFor('attendance.' . AccessLogModel::EVENT_CHECKIN)[0];
        $employee = $this->loggedFor('attendance.' . AccessLogModel::EVENT_CHECKIN . '.employee')[0];

        $this->assertSame('boss@example.com', $admin->to_email);
        $this->assertSame('walker@example.com', $employee->to_email);

        // The employee is named in the admin's body, not addressed by it.
        $this->assertStringContainsString('walker@example.com', (string) $admin->content);
    }

    /**
     * Two audiences, two templates — the receipt is written to the person, the
     * report is written about them.
     */
    public function testTheTwoAudiencesGetDifferentBodies(): void
    {
        Factories::injectMock('config', 'Mail', $this->mailConfig(false));
        $this->loginAs($this->seedWebUser(['email' => 'reader@example.com', 'name' => 'Reader']));
        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);

        $admin    = (string) $this->loggedFor('attendance.' . AccessLogModel::EVENT_CHECKIN)[0]->content;
        $employee = (string) $this->loggedFor('attendance.' . AccessLogModel::EVENT_CHECKIN . '.employee')[0]->content;

        $this->assertNotSame($admin, $employee, 'The two audiences must not share one body.');
        $this->assertStringContainsString('You checked in at', $employee);
        $this->assertStringNotContainsString('You checked in at', $admin);
        // And no configuration names leak into either inbox.
        foreach ([$admin, $employee] as $body) {
            $this->assertStringNotContainsString('mail_notify_', $body);
        }
    }

    public function testTheEmployeeCopyCanBeTurnedOffOnItsOwn(): void
    {
        Factories::injectMock('config', 'Mail', $this->mailConfig(false));
        $this->setSetting(AttendanceNotifier::SETTING_EMPLOYEE_COPY, '0');

        $this->loginAs($this->seedWebUser(['email' => 'adminonly@example.com']));
        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);

        $this->assertCount(1, $this->logged(), 'Only the admin should be mailed.');
        $this->assertCount(1, $this->loggedFor('attendance.' . AccessLogModel::EVENT_CHECKIN));
        $this->assertSame([], $this->loggedFor('attendance.' . AccessLogModel::EVENT_CHECKIN . '.employee'));
    }

    /**
     * The master switch still silences everything, employee copy included.
     */
    public function testTheMasterSwitchSilencesBothAudiences(): void
    {
        Factories::injectMock('config', 'Mail', $this->mailConfig(false));
        $this->setSetting(AttendanceNotifier::SETTING_ENABLED, '0');
        $this->setSetting(AttendanceNotifier::SETTING_EMPLOYEE_COPY, '1');

        $this->loginAs($this->seedWebUser(['email' => 'silent@example.com']));
        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);

        $this->assertSame([], $this->logged());
    }

    /**
     * An employee with no address on file must not stop the admin being told, and
     * must not raise anything into the check-in.
     */
    public function testAnEmployeeWithoutAnAddressStillLetsTheAdminMailThrough(): void
    {
        Factories::injectMock('config', 'Mail', $this->mailConfig(false));

        $user = $this->seedWebUser(['email' => 'noaddress@example.com']);
        $this->loginAs($user);
        // Cleared after login, so the session still works but the row has none.
        model(App\Models\UserModel::class)->update($user->id, ['email' => '']);

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);

        $this->assertCount(1, $this->loggedFor('attendance.' . AccessLogModel::EVENT_CHECKIN), 'The admin is still told.');
        $failed = $this->loggedFor('attendance.' . AccessLogModel::EVENT_CHECKIN . '.employee');
        $this->assertCount(1, $failed, 'The attempt is recorded rather than vanishing.');
        $this->assertSame(Mailer::STATUS_FAILED, (int) $failed[0]->status);
    }

    /**
     * The setting is the renamed one. A stale key name would silently fall back to
     * the built-in default, so mail would keep arriving and nobody would notice the
     * admin's configured address had stopped being read.
     */
    public function testTheRecipientComesFromTheRenamedSetting(): void
    {
        $this->assertSame('mail_notify_admin_recipient', AttendanceNotifier::SETTING_RECIPIENT);

        $this->setSetting('mail_notify_admin_recipient', 'renamed@example.com');
        $this->assertSame('renamed@example.com', (new AttendanceNotifier())->recipient());

        // The old name must no longer be consulted.
        $this->setSetting('mail_notify_recipient', 'old-name@example.com');
        $this->assertSame('renamed@example.com', (new AttendanceNotifier())->recipient());
    }

    /**
     * The migration carries the configured value across rather than re-defaulting
     * it, and leaves no duplicate knob on the settings screen.
     */
    public function testTheRenameMigrationKeepsTheValueAndDropsTheOldRow(): void
    {
        $model = model(SiteVariableModel::class);

        $this->assertNotNull($model->findByName('mail_notify_admin_recipient'), 'The renamed setting should exist after migration.');
        $this->assertNull($model->findByName('mail_notify_recipient'), 'The old row should be gone, not left as a second knob.');

        // The seeded default survived the rename.
        $this->assertSame(AttendanceNotifier::DEFAULT_RECIPIENT, $model->getValue('mail_notify_admin_recipient'));

        // ...and the sibling switch no longer describes a setting that does not exist.
        $description = (string) ($model->findByName('mail_notify_attendance')->description ?? '');
        $this->assertStringContainsString('mail_notify_admin_recipient', $description);
        $this->assertStringNotContainsString('email mail_notify_recipient', $description);
    }
}
