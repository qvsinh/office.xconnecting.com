<?php

use App\Models\AttendanceDayModel;
use App\Models\RoleModel;
use App\Models\SiteVariableModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Attendance management (`Admin\Attendance`) through real routing + filters +
 * CSRF: permission gating (`attendance.manage`, not admin-zone), the correction
 * audit trail, the payroll lock, and the manual-entry path.
 *
 * @internal
 */
final class AdminAttendanceTest extends CIUnitTestCase
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

    private function setVariable(string $name, string $value): void
    {
        $model    = model(SiteVariableModel::class);
        $existing = $model->where('name', $name)->first();

        $existing === null
            ? $model->insert(['name' => $name, 'value' => $value])
            : $model->update((int) $existing->id, ['value' => $value]);
    }

    /**
     * A client-zone user whose role grants `attendance.manage` — the department
     * head this feature is permission-gated for.
     */
    private function seedManager(string $email): object
    {
        $roleModel = model(RoleModel::class);
        $roleId    = (int) $roleModel->insert(['name' => 'attendance-manager', 'label' => 'Attendance manager'], true);

        $permission = $this->db->table('permissions')->where('name', 'attendance.manage')->get()->getRow();
        $this->assertNotNull($permission, 'attendance.manage should be seeded by migration');

        $this->db->table('role_permissions')->insert([
            'role_id'       => $roleId,
            'permission_id' => (int) $permission->id,
        ]);

        return $this->seedWebUser(['email' => $email, 'zone' => 'user', 'role_id' => $roleId]);
    }

    private function seedDay(int $userId, string $workDate, ?string $checkinAt = '2026-07-22 01:30:00', ?string $checkoutAt = '2026-07-22 11:00:00'): object
    {
        $model = model(AttendanceDayModel::class);
        $row   = $model->checkin($userId, '10.0.0.5', $workDate, (string) $checkinAt);

        if ($checkoutAt !== null) {
            $model->checkout((int) $row->id, '10.0.0.5', $checkoutAt);
        }

        return $model->findDay((int) $row->id);
    }

    public function testPlainEmployeeIsRedirectedAwayFromTheManagementPage(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'plain@example.com', 'zone' => 'user']));

        $this->withSession()->get('/admin/attendance')->assertRedirectTo('/dashboard');
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->get('/admin/attendance')->assertRedirectTo('/login');
    }

    public function testAdminZoneUserCanOpenThePage(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'boss@example.com', 'zone' => 'admin']));

        $result = $this->withSession()->get('/admin/attendance');

        $result->assertStatus(200);
        $result->assertSee('Attendance');
    }

    public function testNonAdminWithThePermissionCanOpenThePage(): void
    {
        $this->loginAs($this->seedManager('head@example.com'));

        $this->withSession()->get('/admin/attendance')->assertStatus(200);
    }

    public function testEditReturnsVietnamLocalTimesForTheModal(): void
    {
        $employee = $this->seedWebUser(['email' => 'emp@example.com']);
        $day      = $this->seedDay((int) $employee->id, '2026-07-22');
        $this->loginAs($this->seedWebUser(['email' => 'boss2@example.com', 'zone' => 'admin']));

        $result = $this->withSession()->get('/admin/attendance/edit/' . $day->id);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        // 01:30 UTC -> 08:30 in Vietnam; 11:00 UTC -> 18:00.
        $this->assertSame('08:30', $json['day']['checkin_at']);
        $this->assertSame('18:00', $json['day']['checkout_at']);
        $this->assertFalse($json['day']['locked']);
    }

    public function testEditReturns404ForAnUnknownRecord(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'boss3@example.com', 'zone' => 'admin']));

        $this->withSession()->get('/admin/attendance/edit/999999')->assertStatus(404);
    }

    public function testUpdateStoresUtcAndRecordsTheAuditTrailOnce(): void
    {
        $employee = $this->seedWebUser(['email' => 'emp2@example.com']);
        $day      = $this->seedDay((int) $employee->id, '2026-07-22');
        $manager  = $this->seedWebUser(['email' => 'boss4@example.com', 'zone' => 'admin']);
        $this->loginAs($manager);

        $result = $this->withSession()->post('/admin/attendance/update', $this->withCsrf([
            'id'            => $day->id,
            'checkin_time'  => '09:15',
            'checkout_time' => '18:30',
            'note'          => 'Late train',
        ]));

        $result->assertStatus(200);

        $updated = model(AttendanceDayModel::class)->findDay((int) $day->id);
        // 09:15 Vietnam -> 02:15 UTC.
        $this->assertSame('2026-07-22 02:15:00', $updated->checkin_at);
        $this->assertSame('2026-07-22 11:30:00', $updated->checkout_at);
        $this->assertSame('Late train', $updated->note);
        $this->assertSame((int) $manager->id, (int) $updated->edited_by);
        $this->assertNotNull($updated->edited_at);
        $this->assertSame('2026-07-22 01:30:00', $updated->original_checkin_at);

        // A second correction keeps the employee's original value.
        $this->withSession()->post('/admin/attendance/update', $this->withCsrf([
            'id'            => $day->id,
            'checkin_time'  => '09:45',
            'checkout_time' => '18:30',
        ]));

        $twice = model(AttendanceDayModel::class)->findDay((int) $day->id);
        $this->assertSame('2026-07-22 02:45:00', $twice->checkin_at);
        $this->assertSame('2026-07-22 01:30:00', $twice->original_checkin_at);
    }

    public function testUpdateRejectsACheckoutEarlierThanTheCheckin(): void
    {
        $employee = $this->seedWebUser(['email' => 'emp3@example.com']);
        $day      = $this->seedDay((int) $employee->id, '2026-07-22');
        $this->loginAs($this->seedWebUser(['email' => 'boss5@example.com', 'zone' => 'admin']));

        $result = $this->withSession()->post('/admin/attendance/update', $this->withCsrf([
            'id'            => $day->id,
            'checkin_time'  => '18:00',
            'checkout_time' => '08:30',
        ]));

        $result->assertStatus(400);
        $unchanged = model(AttendanceDayModel::class)->findDay((int) $day->id);
        $this->assertSame('2026-07-22 01:30:00', $unchanged->checkin_at);
    }

    public function testUpdateRejectsACheckoutWithoutACheckin(): void
    {
        $employee = $this->seedWebUser(['email' => 'emp4@example.com']);
        $day      = $this->seedDay((int) $employee->id, '2026-07-22');
        $this->loginAs($this->seedWebUser(['email' => 'boss6@example.com', 'zone' => 'admin']));

        $result = $this->withSession()->post('/admin/attendance/update', $this->withCsrf([
            'id'            => $day->id,
            'checkin_time'  => '',
            'checkout_time' => '18:00',
        ]));

        $result->assertStatus(400);
    }

    public function testUpdateRejectsAMalformedTime(): void
    {
        $employee = $this->seedWebUser(['email' => 'emp5@example.com']);
        $day      = $this->seedDay((int) $employee->id, '2026-07-22');
        $this->loginAs($this->seedWebUser(['email' => 'boss7@example.com', 'zone' => 'admin']));

        $this->withSession()->post('/admin/attendance/update', $this->withCsrf([
            'id'           => $day->id,
            'checkin_time' => '25:99',
        ]))->assertStatus(400);
    }

    public function testUpdateIsRefusedInsideALockedPayrollMonth(): void
    {
        $employee = $this->seedWebUser(['email' => 'emp6@example.com']);
        $day      = $this->seedDay((int) $employee->id, '2026-07-22');
        $this->setVariable('attendance_locked_through', '2026-07');
        $this->loginAs($this->seedWebUser(['email' => 'boss8@example.com', 'zone' => 'admin']));

        $result = $this->withSession()->post('/admin/attendance/update', $this->withCsrf([
            'id'           => $day->id,
            'checkin_time' => '09:00',
        ]));

        $result->assertStatus(409);
        $this->assertSame('2026-07-22 01:30:00', model(AttendanceDayModel::class)->findDay((int) $day->id)->checkin_at);
    }

    public function testCreateAddsAManualRecordAttributedToTheManager(): void
    {
        $employee = $this->seedWebUser(['email' => 'emp7@example.com']);
        $manager  = $this->seedWebUser(['email' => 'boss9@example.com', 'zone' => 'admin']);
        $this->loginAs($manager);

        $result = $this->withSession()->post('/admin/attendance/create', $this->withCsrf([
            'user_id'       => $employee->id,
            'work_date'     => '2026-07-20',
            'checkin_time'  => '08:30',
            'checkout_time' => '18:00',
            'note'          => 'Forgot to check in',
        ]));

        $result->assertStatus(200);

        $row = model(AttendanceDayModel::class)->dayFor((int) $employee->id, '2026-07-20');
        $this->assertNotNull($row);
        $this->assertSame('2026-07-20 01:30:00', $row->checkin_at);
        $this->assertSame('2026-07-20 11:00:00', $row->checkout_at);
        // A manager-created row is visibly manager-created.
        $this->assertSame((int) $manager->id, (int) $row->edited_by);
    }

    public function testCreateRejectsADuplicateDayWithAMessageNotAServerError(): void
    {
        $employee = $this->seedWebUser(['email' => 'emp8@example.com']);
        $this->seedDay((int) $employee->id, '2026-07-22');
        $this->loginAs($this->seedWebUser(['email' => 'boss10@example.com', 'zone' => 'admin']));

        $result = $this->withSession()->post('/admin/attendance/create', $this->withCsrf([
            'user_id'      => $employee->id,
            'work_date'    => '2026-07-22',
            'checkin_time' => '08:30',
        ]));

        $result->assertStatus(409);
        $this->assertSame(1, model(AttendanceDayModel::class)->countAdmin(['user_id' => (int) $employee->id]));
    }

    public function testCreateRejectsAnUnknownEmployeeAndAnInvalidDate(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'boss11@example.com', 'zone' => 'admin']));

        $this->withSession()->post('/admin/attendance/create', $this->withCsrf([
            'user_id'   => 999999,
            'work_date' => '2026-07-20',
        ]))->assertStatus(404);

        $this->withSession()->post('/admin/attendance/create', $this->withCsrf([
            'user_id'   => 1,
            'work_date' => 'not-a-date',
        ]))->assertStatus(400);
    }

    public function testMissingCheckoutFilterReturnsOnlyFlaggedRows(): void
    {
        $employee = $this->seedWebUser(['email' => 'emp9@example.com']);
        $complete = $this->seedDay((int) $employee->id, '2026-07-21');
        $open     = $this->seedDay((int) $employee->id, '2026-07-22', '2026-07-22 01:30:00', null);
        model(AttendanceDayModel::class)->flagMissingCheckout([(int) $open->id]);
        $this->loginAs($this->seedWebUser(['email' => 'boss12@example.com', 'zone' => 'admin']));

        $result = $this->withSession()->get('/admin/attendance?missing_checkout=1');

        $result->assertStatus(200);
        $result->assertSee('2026-07-22');
        $result->assertDontSee($complete->work_date);
    }

    public function testUpdateRequiresCsrf(): void
    {
        $employee = $this->seedWebUser(['email' => 'emp10@example.com']);
        $day      = $this->seedDay((int) $employee->id, '2026-07-22');
        $this->loginAs($this->seedWebUser(['email' => 'boss13@example.com', 'zone' => 'admin']));

        $result = $this->withSession()->post('/admin/attendance/update', [
            'id'           => $day->id,
            'checkin_time' => '09:00',
        ]);

        $this->assertNotSame(200, $result->response()->getStatusCode());
        $this->assertSame('2026-07-22 01:30:00', model(AttendanceDayModel::class)->findDay((int) $day->id)->checkin_at);
    }
}
