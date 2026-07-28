<?php

use App\Models\AttendanceDayModel;
use App\Models\SiteVariableModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Employee self-service attendance (`Attendance`) through real routing +
 * filters + CSRF.
 *
 * The two cases that matter most for trust in this data:
 *  - a forged `X-Forwarded-For` must not defeat the office-IP allowlist while
 *    `Config\App::$proxyIPs` is empty (card §0);
 *  - `work_date` must follow the Vietnam calendar, not the UTC clock, or every
 *    evening check-in lands on the wrong day.
 *
 * @internal
 */
final class AttendanceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
        session()->destroy();
    }

    private function setVariable(string $name, string $value): void
    {
        $model    = model(SiteVariableModel::class);
        $existing = $model->where('name', $name)->first();

        $existing === null
            ? $model->insert(['name' => $name, 'value' => $value])
            : $model->update((int) $existing->id, ['value' => $value]);
    }

    private function enforceOfficeIps(string $list): void
    {
        $this->setVariable('attendance_ip_enforce', '1');
        $this->setVariable('attendance_office_ips', $list);
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->get('/attendance')->assertRedirectTo('/login');
    }

    public function testPageRendersForAnAuthenticatedEmployee(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'render@example.com']));

        $result = $this->withSession()->get('/attendance');

        $result->assertStatus(200);
        $result->assertSee('My attendance');
    }

    public function testCheckinRecordsTheDayAndSecondAttemptIsRejected(): void
    {
        $user = $this->seedWebUser(['email' => 'checkin@example.com']);
        $this->loginAs($user);

        $first = $this->withSession()->post('/attendance/checkin', $this->withCsrf([]));
        $first->assertStatus(200);
        $json = json_decode($first->getJSON(), true);
        $this->assertSame('success', $json['status']);
        $this->assertNotNull($json['day']['checkin_at']);
        $this->assertNull($json['day']['checkout_at']);

        $row = model(AttendanceDayModel::class)->todayFor((int) $user->id);
        $this->assertNotNull($row);
        $this->assertNotNull($row->checkin_at);

        $second = $this->withSession()->post('/attendance/checkin', $this->withCsrf([]));
        $second->assertStatus(409);
        $this->assertSame(1, model(AttendanceDayModel::class)->countForUser((int) $user->id));
    }

    public function testCheckoutRequiresACheckinThenRecordsAndCanBeRepeated(): void
    {
        $user = $this->seedWebUser(['email' => 'checkout@example.com']);
        $this->loginAs($user);

        $this->withSession()->post('/attendance/checkout', $this->withCsrf([]))->assertStatus(409);

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]));
        $out = $this->withSession()->post('/attendance/checkout', $this->withCsrf([]));

        $out->assertStatus(200);
        $json = json_decode($out->getJSON(), true);
        $this->assertNotNull($json['day']['checkout_at']);

        // Last check-out wins — an accidental early tap is recoverable.
        $again = $this->withSession()->post('/attendance/checkout', $this->withCsrf([]));
        $again->assertStatus(200);

        $row = model(AttendanceDayModel::class)->todayFor((int) $user->id);
        $this->assertNotNull($row->checkout_at);
        $this->assertSame(0, (int) $row->missing_checkout);
    }

    public function testCheckinIsBlockedFromOutsideTheOfficeAllowlist(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'remote@example.com']));
        // The feature-test request presents 0.0.0.0 as REMOTE_ADDR, so an
        // allowlist that excludes it is enough to prove the gate closes.
        $this->enforceOfficeIps('203.0.113.10');

        $blocked = $this->withSession()->post('/attendance/checkin', $this->withCsrf([]));

        $blocked->assertStatus(403);
        $this->assertSame(0, model(AttendanceDayModel::class)->countAdmin([]));
    }

    public function testForgedForwardedForHeaderDoesNotBypassTheAllowlist(): void
    {
        // `Config\App::$proxyIPs` is empty, so a client-supplied
        // X-Forwarded-For must be ignored outright. If this ever starts
        // passing, the office-IP restriction has become spoofable and the
        // whole feature is security theatre — see the card's §0.
        $this->loginAs($this->seedWebUser(['email' => 'spoofer@example.com']));
        $this->enforceOfficeIps('203.0.113.10');

        $spoofed = $this->withHeaders([
            'X-Forwarded-For' => '203.0.113.10',
            'Client-IP'       => '203.0.113.10',
        ])->withSession()->post('/attendance/checkin', $this->withCsrf([]));

        $spoofed->assertStatus(403);
        $this->assertSame(0, model(AttendanceDayModel::class)->countAdmin([]));
    }

    public function testCheckinIsAllowedWhenTheRequestAddressIsOnTheAllowlist(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'onsite@example.com']));
        // 0.0.0.0/0 covers whatever address the test client presents, proving
        // the gate opens rather than being unconditionally shut.
        $this->enforceOfficeIps('0.0.0.0/0');

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);
    }

    public function testEnforcementOffLetsAnyAddressCheckIn(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'lenient@example.com']));
        $this->setVariable('attendance_ip_enforce', '0');
        $this->setVariable('attendance_office_ips', '203.0.113.10');

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);
    }

    public function testWorkDateFollowsTheVietnamCalendarNotUtc(): void
    {
        $user = $this->seedWebUser(['email' => 'midnight@example.com']);
        $this->loginAs($user);

        // 17:30 UTC on the 21st is already 00:30 on the 22nd in Vietnam.
        Time::setTestNow(Time::parse('2026-07-21 17:30:00', 'UTC'));

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);

        $rows = model(AttendanceDayModel::class)->historyFor((int) $user->id);
        $this->assertCount(1, $rows);
        $this->assertSame('2026-07-22', $rows[0]->work_date);
        // ...while the stored timestamp itself stays UTC.
        $this->assertStringStartsWith('2026-07-21 17:30', (string) $rows[0]->checkin_at);
    }

    public function testAdminActingAsSomebodyElseCannotRecordTheirAttendance(): void
    {
        $employee = $this->seedWebUser(['email' => 'victim@example.com']);
        $admin    = $this->seedWebUser(['email' => 'impersonator@example.com', 'zone' => 'admin']);
        $this->loginAs($admin);

        // Act as the employee, then try to record attendance in their name.
        $this->withSession()->post('/admin/users/act-as/' . $employee->id, $this->withCsrf([]));

        $blocked = $this->withSession()->post('/attendance/checkin', $this->withCsrf([]));

        $blocked->assertStatus(403);
        $this->assertNull(model(AttendanceDayModel::class)->todayFor((int) $employee->id));
    }

    public function testCheckinRequiresCsrf(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'nocsrf@example.com']));

        $result = $this->withSession()->post('/attendance/checkin', []);

        $this->assertNotSame(200, $result->response()->getStatusCode());
        $this->assertSame(0, model(AttendanceDayModel::class)->countAdmin([]));
    }

    /**
     * The card's initial state, decoded from the `x-data` attribute.
     *
     * @return array<string, mixed>
     */
    private function cardState(string $html): array
    {
        // `esc($json, 'attr')` hex-encodes every non-alphanumeric character,
        // brackets included, so decode the page before matching.
        $decoded = html_entity_decode($html, ENT_QUOTES, 'UTF-8');

        $this->assertSame(1, preg_match('/attendanceCard\((\{.+?\})\)/s', $decoded, $m), 'The attendance card was not rendered.');

        return json_decode($m[1], true);
    }

    /**
     * Regression: an employee who has not arrived yet must be offered "Check in".
     *
     * The card picks its button from whether `checkin_at` is set, so that value
     * has to be **null** — not a `'—'` placeholder, which is truthy in
     * JavaScript. When it was a dash, every employee arriving in the morning was
     * shown "Check out", and pressing it failed with "You have not checked in
     * today." Formatting a display fallback into machine-read state is the bug;
     * asserting on null is what catches it coming back.
     */
    public function testTheCardOffersCheckinBeforeArrivalAndCheckoutAfter(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'buttons@example.com']));

        $before = $this->cardState((string) $this->withSession()->get('/attendance')->getBody());
        $this->assertNull($before['checkin_at'], 'Before arriving, checkin_at must be null so "Check in" is shown.');
        $this->assertNull($before['checkout_at']);

        // Same rule for the payload the card re-renders itself from.
        $json = json_decode($this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->getJSON(), true);
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $json['day']['checkin_at']);
        $this->assertNull($json['day']['checkout_at'], 'Not checked out yet — must be null, not a dash.');

        // ...and on a fresh page load, now that a check-in exists.
        $after = $this->cardState((string) $this->withSession()->get('/attendance')->getBody());
        $this->assertMatchesRegularExpression('/^\d{2}:\d{2}$/', $after['checkin_at']);
        $this->assertNull($after['checkout_at']);
    }

    /**
     * Somebody who took the morning off checks in after lunch. Nothing may gate
     * a check-in on the time of day.
     */
    public function testAnAfternoonArrivalCanStillCheckIn(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'afternoon@example.com']));

        helper('attendance');
        // 08:30 UTC is 15:30 in Vietnam — mid-afternoon, hours past the start.
        Time::setTestNow(Time::parse(attendance_today() . ' 08:30:00', 'UTC'));

        try {
            $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);
            $this->withSession()->post('/attendance/checkout', $this->withCsrf([]))->assertStatus(200);
        } finally {
            Time::setTestNow();
        }
    }
}
