<?php

use App\Models\AttendanceDayModel;
use App\Models\UserModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * `users.attendance_exempt` — an employee who is simply not on the clock.
 *
 * Two halves, and the second is the one worth guarding: the flag must remove them
 * from every screen that *measures* attendance, and must not remove them from the
 * pickers where they are still a person — they still take leave and still sign in.
 * A single blanket filter on the roster would have quietly emptied those two
 * dropdowns.
 *
 * @internal
 */
final class AttendanceExemptionTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate = true;
    protected $refresh = true;
    protected $namespace;

    protected function tearDown(): void
    {
        Time::setTestNow();
        parent::tearDown();
        session()->destroy();
    }

    private function loginAsManager(): object
    {
        $admin = $this->seedWebUser(['email' => 'boss@example.com', 'zone' => 'admin', 'name' => 'The Boss']);
        $this->loginAs($admin);

        return $admin;
    }

    /**
     * One exempt employee and one ordinary one, so every assertion below has a
     * control: "absent from the page" only means something if somebody else is on it.
     */
    private function seedPair(): void
    {
        $this->seedWebUser(['email' => 'director@example.com', 'name' => 'Off The Clock', 'attendance_exempt' => 1]);
        $this->seedWebUser(['email' => 'staff@example.com', 'name' => 'On The Clock']);
    }

    // ------------------------------------------------ removed from attendance

    public function testTheTodayBoardLeavesThemOut(): void
    {
        // 11:00 Vietnam on a Wednesday: past the start time, so an ordinary
        // employee with no row would read "Absent" — which is exactly the noise
        // the flag exists to remove.
        Time::setTestNow(Time::parse('2026-07-22 04:00:00', 'UTC'));

        $this->loginAsManager();
        $this->seedPair();

        $result = $this->withSession()->get('/admin/attendance/today');

        $result->assertStatus(200);
        $result->assertSee('On The Clock');
        $result->assertDontSee('Off The Clock');
    }

    public function testTheMonthlyReportLeavesThemOut(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 04:00:00', 'UTC'));

        $this->loginAsManager();
        $this->seedPair();

        $result = $this->withSession()->get('/admin/attendance/report?month=2026-07');

        $result->assertStatus(200);
        $result->assertSee('On The Clock');
        $result->assertDontSee('Off The Clock');
    }

    public function testTheAdminDashboardLeavesThemOut(): void
    {
        Time::setTestNow(Time::parse('2026-07-22 04:00:00', 'UTC'));

        $this->loginAsManager();
        $this->seedPair();

        $result = $this->withSession()->get('/admin/dashboard');

        $result->assertStatus(200);
        $result->assertDontSee('Off The Clock');
    }

    /**
     * The deliberate non-exclusion. An exempt employee still books leave and still
     * logs in, so removing them from these two pickers would hide their records
     * behind a filter that can no longer name them.
     */
    public function testTheyAreStillOfferedByTheRequestAndAccessLogFilters(): void
    {
        $this->loginAsManager();
        $this->seedPair();

        $this->withSession()->get('/admin/requests')->assertSee('Off The Clock');
        $this->withSession()->get('/admin/access-logs')->assertSee('Off The Clock');
    }

    public function testTheRosterMethodsDisagreeExactlyOnceAboutThisPerson(): void
    {
        $this->seedPair();

        $model  = model(UserModel::class);
        $names  = static fn (array $rows): array => array_map(static fn (object $r): string => (string) $r->name, $rows);
        $active = $names($model->activeRoster());

        $this->assertContains('Off The Clock', $active);
        $this->assertContains('On The Clock', $active);

        $roster = $names($model->attendanceRoster());
        $this->assertNotContains('Off The Clock', $roster);
        $this->assertContains('On The Clock', $roster);
    }

    // --------------------------------------------------- their own experience

    public function testTheirOwnCheckInIsRefusedAndRecordsNothing(): void
    {
        $user = $this->seedWebUser(['email' => 'director@example.com', 'attendance_exempt' => 1]);
        $this->loginAs($user);

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(403);
        $this->withSession()->post('/attendance/checkout', $this->withCsrf([]))->assertStatus(403);

        // Not merely hidden in the UI: nothing was stored.
        $this->assertSame(0, model(AttendanceDayModel::class)->countForUser((int) $user->id));
    }

    public function testTheirOwnPageExplainsWhyThereIsNothingToPress(): void
    {
        $user = $this->seedWebUser(['email' => 'director@example.com', 'attendance_exempt' => 1]);
        $this->loginAs($user);

        $result = $this->withSession()->get('/attendance');

        $result->assertStatus(200);
        $result->assertSee('does not record check-in or check-out');
    }

    public function testAnOrdinaryEmployeeIsUnaffected(): void
    {
        $user = $this->seedWebUser(['email' => 'staff@example.com']);
        $this->loginAs($user);

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);
        $this->assertSame(1, model(AttendanceDayModel::class)->countForUser((int) $user->id));
    }

    /**
     * Read from the row, not the session: clearing the flag has to take effect on
     * the next request, not the next login.
     */
    public function testClearingTheFlagLetsThemCheckInWithoutSigningInAgain(): void
    {
        $user = $this->seedWebUser(['email' => 'director@example.com', 'attendance_exempt' => 1]);
        $this->loginAs($user);

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(403);

        model(UserModel::class)->update($user->id, ['attendance_exempt' => 0]);

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);
    }

    // ------------------------------------------------------------- admin edit

    public function testAnAdminCanSetAndClearTheFlagOnAnExistingUser(): void
    {
        $this->loginAsManager();
        $staff = $this->seedWebUser(['email' => 'staff@example.com', 'name' => 'On The Clock']);

        $payload = [
            'id'     => $staff->id,
            'name'   => 'On The Clock',
            'email'  => 'staff@example.com',
            'zone'   => 'user',
            'status' => 1,
        ];

        $this->withSession()->post('/admin/users/update', $this->withCsrf($payload + ['attendance_exempt' => 1]))
            ->assertStatus(200);
        $this->seeInDatabase('users', ['id' => $staff->id, 'attendance_exempt' => 1]);

        $this->withSession()->post('/admin/users/update', $this->withCsrf($payload + ['attendance_exempt' => 0]))
            ->assertStatus(200);
        $this->seeInDatabase('users', ['id' => $staff->id, 'attendance_exempt' => 0]);
    }

    public function testTheEditPayloadCarriesTheFlagSoTheModalShowsItTicked(): void
    {
        $this->loginAsManager();
        $exempt = $this->seedWebUser(['email' => 'director@example.com', 'attendance_exempt' => 1]);

        $result = $this->withSession()->get('/admin/users/edit/' . $exempt->id);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(1, $json['user']['attendance_exempt']);
    }

    public function testANewUserCanBeCreatedExemptAndDefaultsToOnTheClock(): void
    {
        $this->loginAsManager();

        $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'              => 'New Director',
            'email'             => 'new-director@example.com',
            'password'          => 'Str0ng!Password1',
            'zone'              => 'user',
            'status'            => 1,
            'attendance_exempt' => 1,
        ]))->assertStatus(200);

        $this->seeInDatabase('users', ['email' => 'new-director@example.com', 'attendance_exempt' => 1]);

        $this->withSession()->post('/admin/users/create', $this->withCsrf([
            'name'     => 'New Staff',
            'email'    => 'new-staff@example.com',
            'password' => 'Str0ng!Password1',
            'zone'     => 'user',
            'status'   => 1,
        ]))->assertStatus(200);

        $this->seeInDatabase('users', ['email' => 'new-staff@example.com', 'attendance_exempt' => 0]);
    }

    public function testTheFlagOnlyAcceptsZeroOrOne(): void
    {
        $this->loginAsManager();
        $staff = $this->seedWebUser(['email' => 'staff@example.com']);

        $result = $this->withSession()->post('/admin/users/update', $this->withCsrf([
            'id'                => $staff->id,
            'name'              => 'On The Clock',
            'email'             => 'staff@example.com',
            'zone'              => 'user',
            'status'            => 1,
            'attendance_exempt' => 'yes-please',
        ]));

        // Validation refusals come back 400 from `jsonFailed()`.
        $result->assertStatus(400);
        $this->assertSame('failed', json_decode($result->getJSON(), true)['status']);
        $this->seeInDatabase('users', ['id' => $staff->id, 'attendance_exempt' => 0]);
    }
}
