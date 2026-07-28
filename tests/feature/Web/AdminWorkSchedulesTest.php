<?php

use App\Models\UserModel;
use App\Models\WorkScheduleModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Work-schedule CRUD (`Admin\WorkSchedules`) through real routing + filters +
 * CSRF: permission gating, the ordering rules the hour arithmetic depends on,
 * the single-default invariant, and the delete guards.
 *
 * @internal
 */
final class AdminWorkSchedulesTest extends CIUnitTestCase
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

    private function loginAsManager(string $email = 'boss@example.com'): object
    {
        $admin = $this->seedWebUser(['email' => $email, 'zone' => 'admin']);
        $this->loginAs($admin);

        return $admin;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name'          => 'Late shift',
            'start_time'    => '10:00',
            'end_time'      => '19:00',
            'break_start'   => '13:00',
            'break_minutes' => 60,
            'grace_minutes' => 0,
            'workdays'      => '1,2,3,4,5',
        ], $overrides);
    }

    public function testPlainEmployeeCannotOpenThePage(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'plain@example.com', 'zone' => 'user']));

        $this->withSession()->get('/admin/attendance/schedules')->assertRedirectTo('/dashboard');
    }

    public function testPageShowsTheSeededCompanyBaseline(): void
    {
        $this->loginAsManager();

        $result = $this->withSession()->get('/admin/attendance/schedules');

        $result->assertStatus(200);
        $result->assertSee('Work schedules');
        $result->assertSee('08:30');
        $result->assertSee('18:00');
        // 9h30 − 1h30 break = 8h00 net, computed for the admin rather than left
        // as mental arithmetic.
        $result->assertSee('8h 00m');
    }

    public function testCreateStoresTheScheduleWithSortedWorkdays(): void
    {
        $this->loginAsManager();

        $result = $this->withSession()->post('/admin/attendance/schedules/create', $this->withCsrf($this->payload([
            'workdays' => '5,1,3,1',
        ])));

        $result->assertStatus(200);

        $row = model(WorkScheduleModel::class)->where('name', 'Late shift')->first();
        $this->assertNotNull($row);
        $this->assertSame('10:00:00', $row->start_time);
        $this->assertSame('19:00:00', $row->end_time);
        $this->assertSame('13:00:00', $row->break_start);
        $this->assertSame(60, (int) $row->break_minutes);
        // Deduplicated and ordered, so the stored value is canonical.
        $this->assertSame('1,3,5', $row->workdays);
        // Not promoted over the existing default unless asked.
        $this->assertSame(0, (int) $row->is_default);
    }

    public function testCreateRejectsAnEndBeforeStart(): void
    {
        $this->loginAsManager();

        $result = $this->withSession()->post('/admin/attendance/schedules/create', $this->withCsrf($this->payload([
            'start_time' => '18:00',
            'end_time'   => '09:00',
        ])));

        $result->assertStatus(400);
        $this->assertNull(model(WorkScheduleModel::class)->where('name', 'Late shift')->first());
    }

    public function testCreateRejectsALunchBreakOutsideTheWorkingWindow(): void
    {
        $this->loginAsManager();

        // Lunch would end at 20:30, after the 19:00 finish.
        $this->withSession()->post('/admin/attendance/schedules/create', $this->withCsrf($this->payload([
            'break_start'   => '19:30',
            'break_minutes' => 60,
        ])))->assertStatus(400);

        // ...and a break as long as the day leaves zero paid hours.
        $this->withSession()->post('/admin/attendance/schedules/create', $this->withCsrf($this->payload([
            'break_minutes' => 600,
        ])))->assertStatus(400);

        $this->assertNull(model(WorkScheduleModel::class)->where('name', 'Late shift')->first());
    }

    public function testCreateRejectsEmptyOrInvalidWorkdays(): void
    {
        $this->loginAsManager();

        $this->withSession()->post('/admin/attendance/schedules/create', $this->withCsrf($this->payload([
            'workdays' => ' , ',
        ])))->assertStatus(400);

        $this->withSession()->post('/admin/attendance/schedules/create', $this->withCsrf($this->payload([
            'workdays' => '1,9',
        ])))->assertStatus(400);
    }

    public function testEditReturnsTheScheduleForTheModal(): void
    {
        $this->loginAsManager();
        $schedule = model(WorkScheduleModel::class)->defaultSchedule();

        $result = $this->withSession()->get('/admin/attendance/schedules/edit/' . $schedule->id);

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame('08:30', $json['schedule']['start_time']);
        $this->assertSame('12:00', $json['schedule']['break_start']);
        $this->assertSame(90, $json['schedule']['break_minutes']);
        $this->assertSame(['1', '2', '3', '4', '5'], $json['schedule']['workdays']);
        $this->assertTrue($json['schedule']['is_default']);
    }

    public function testEditReturns404ForAnUnknownSchedule(): void
    {
        $this->loginAsManager();

        $this->withSession()->get('/admin/attendance/schedules/edit/999999')->assertStatus(404);
    }

    public function testUpdateChangesTheHours(): void
    {
        $this->loginAsManager();
        $schedule = model(WorkScheduleModel::class)->defaultSchedule();

        $result = $this->withSession()->post('/admin/attendance/schedules/update', $this->withCsrf($this->payload([
            'id'         => $schedule->id,
            'name'       => 'Office (09:00–18:00)',
            'start_time' => '09:00',
            'end_time'   => '18:00',
        ])));

        $result->assertStatus(200);

        $updated = model(WorkScheduleModel::class)->findSchedule((int) $schedule->id);
        $this->assertSame('09:00:00', $updated->start_time);
        $this->assertSame('Office (09:00–18:00)', $updated->name);
    }

    public function testPromotingADefaultDemotesTheOldOne(): void
    {
        $this->loginAsManager();
        $original = model(WorkScheduleModel::class)->defaultSchedule();

        $this->withSession()->post('/admin/attendance/schedules/create', $this->withCsrf($this->payload([
            'is_default' => '1',
        ])))->assertStatus(200);

        $model = model(WorkScheduleModel::class);
        $this->assertSame(0, (int) $model->findSchedule((int) $original->id)->is_default);
        $this->assertSame('Late shift', $model->defaultSchedule()->name);
        // Exactly one default, always.
        $this->assertSame(1, $model->where('is_default', 1)->countAllResults());
    }

    public function testDeleteIsRefusedWhileEmployeesStillUseTheSchedule(): void
    {
        $this->loginAsManager();
        $model = model(WorkScheduleModel::class);
        $id    = (int) $model->insert($this->payload() + ['start_time' => '10:00:00', 'end_time' => '19:00:00', 'break_start' => '13:00:00'], true);

        $employee = $this->seedWebUser(['email' => 'assigned@example.com']);
        model(UserModel::class)->update((int) $employee->id, ['work_schedule_id' => $id]);

        $result = $this->withSession()->post('/admin/attendance/schedules/delete', $this->withCsrf(['id' => $id]));

        $result->assertStatus(409);
        $this->assertNotNull($model->findSchedule($id));
    }

    public function testDeleteIsRefusedForTheOnlyRemainingSchedule(): void
    {
        $this->loginAsManager();
        $schedule = model(WorkScheduleModel::class)->defaultSchedule();

        $result = $this->withSession()->post('/admin/attendance/schedules/delete', $this->withCsrf(['id' => $schedule->id]));

        $result->assertStatus(409);
        $this->assertNotNull(model(WorkScheduleModel::class)->findSchedule((int) $schedule->id));
    }

    public function testDeletingTheDefaultPromotesAnotherSoTheCompanyIsNeverWithoutOne(): void
    {
        $this->loginAsManager();
        $model    = model(WorkScheduleModel::class);
        $original = $model->defaultSchedule();

        $this->withSession()->post('/admin/attendance/schedules/create', $this->withCsrf($this->payload()))->assertStatus(200);
        $this->withSession()->post('/admin/attendance/schedules/delete', $this->withCsrf(['id' => $original->id]))->assertStatus(200);

        $this->assertNull($model->findSchedule((int) $original->id));
        $fallback = $model->defaultSchedule();
        $this->assertNotNull($fallback);
        $this->assertSame(1, (int) $fallback->is_default);
    }

    public function testCreateRequiresCsrf(): void
    {
        $this->loginAsManager();

        $result = $this->withSession()->post('/admin/attendance/schedules/create', $this->payload());

        $this->assertNotSame(200, $result->response()->getStatusCode());
        $this->assertNull(model(WorkScheduleModel::class)->where('name', 'Late shift')->first());
    }
}
