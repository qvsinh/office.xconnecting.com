<?php

use App\Libraries\AttendanceCalendar;
use App\Models\HolidayModel;
use App\Models\LeaveRequestModel;
use App\Models\NotificationModel;
use App\Models\SiteVariableModel;
use CodeIgniter\I18n\Time;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Leave / late-arrival requests through real routing + filters + CSRF, both the
 * employee side (`Requests`) and the approval queue (`Admin\Requests`).
 *
 * The cases that matter:
 *  - an employee can only ever see/act on their own requests (no IDOR);
 *  - overlapping and no-workday requests are refused at submit time;
 *  - two simultaneous approvals cannot both win;
 *  - an approval inside a locked payroll month is refused;
 *  - approving actually changes the attendance sheet.
 *
 * @internal
 */
final class RequestsTest extends CIUnitTestCase
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

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function leavePayload(array $overrides = []): array
    {
        return array_merge([
            'type'        => 'leave',
            'leave_kind'  => 'annual',
            'start_date'  => '2026-07-22', // a Wednesday
            'end_date'    => '',
            'day_portion' => 'full',
            'reason'      => 'Family matter',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedRequest(int $userId, array $overrides = []): object
    {
        $model = model(LeaveRequestModel::class);
        $id    = (int) $model->insert(array_merge([
            'user_id'     => $userId,
            'type'        => LeaveRequestModel::TYPE_LEAVE,
            'leave_kind'  => 'annual',
            'start_date'  => '2026-07-22',
            'end_date'    => '2026-07-22',
            'day_portion' => 'full',
            'reason'      => 'Family matter',
            'status'      => LeaveRequestModel::STATUS_PENDING,
        ], $overrides), true);

        return $model->findRequest($id);
    }

    // ------------------------------------------------------------------ employee

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->get('/requests')->assertRedirectTo('/login');
    }

    public function testPageShowsTheBalanceAndRendersForAnEmployee(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'emp@example.com']));

        $result = $this->withSession()->get('/requests');

        $result->assertStatus(200);
        $result->assertSee('My requests');
        $result->assertSee('12 days'); // the seeded annual quota
    }

    public function testCreateStoresAPendingLeaveRequest(): void
    {
        $employee = $this->seedWebUser(['email' => 'creator@example.com']);
        $this->loginAs($employee);

        $result = $this->withSession()->post('/requests/create', $this->withCsrf($this->leavePayload()));

        $result->assertStatus(200);

        $row = model(LeaveRequestModel::class)->forUser((int) $employee->id)[0] ?? null;
        $this->assertNotNull($row);
        $this->assertSame(LeaveRequestModel::STATUS_PENDING, $row->status);
        $this->assertSame('2026-07-22', $row->start_date);
        // A blank end date defaults to the start date rather than staying null.
        $this->assertSame('2026-07-22', $row->end_date);
        $this->assertSame('full', $row->day_portion);
    }

    public function testCreateAcceptsARangeAndALateRequest(): void
    {
        $employee = $this->seedWebUser(['email' => 'ranger@example.com']);
        $this->loginAs($employee);

        $this->withSession()->post('/requests/create', $this->withCsrf($this->leavePayload([
            'start_date' => '2026-07-20',
            'end_date'   => '2026-07-24',
        ])))->assertStatus(200);

        $this->withSession()->post('/requests/create', $this->withCsrf([
            'type'          => 'late',
            'start_date'    => '2026-07-27',
            'expected_time' => '09:30',
            'reason'        => 'Doctor appointment',
        ]))->assertStatus(200);

        $rows = model(LeaveRequestModel::class)->forUser((int) $employee->id);
        $this->assertCount(2, $rows);
    }

    public function testCreateRejectsABadTypeEmptyReasonAndAnEndBeforeStart(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'invalid@example.com']));

        $this->withSession()->post('/requests/create', $this->withCsrf($this->leavePayload(['type' => 'holiday'])))->assertStatus(400);
        $this->withSession()->post('/requests/create', $this->withCsrf($this->leavePayload(['reason' => ''])))->assertStatus(400);
        $this->withSession()->post('/requests/create', $this->withCsrf($this->leavePayload([
            'start_date' => '2026-07-24',
            'end_date'   => '2026-07-20',
        ])))->assertStatus(400);

        $this->assertSame(0, model(LeaveRequestModel::class)->countAdmin([]));
    }

    public function testLeaveWithoutAKindIsRejected(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'nokind@example.com']));

        $this->withSession()->post('/requests/create', $this->withCsrf($this->leavePayload(['leave_kind' => ''])))->assertStatus(400);
    }

    public function testAHalfDayOnARangeIsRejected(): void
    {
        // The browser resets day_portion to 'full' when a range is picked (the
        // portion select is hidden by then), but the server is the real boundary:
        // if the stale value ever arrives it must be refused, not stored.
        $this->loginAs($this->seedWebUser(['email' => 'stalehalf@example.com']));

        $result = $this->withSession()->post('/requests/create', $this->withCsrf($this->leavePayload([
            'start_date'  => '2026-07-20',
            'end_date'    => '2026-07-24',
            'day_portion' => 'morning',
        ])));

        $result->assertStatus(400);
        $this->assertStringContainsString('single date', json_decode($result->getJSON(), true)['message']);
        $this->assertSame(0, model(LeaveRequestModel::class)->countAdmin([]));
    }

    public function testARangeWithNoWorkingDayIsRejected(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'weekend@example.com']));

        // 2026-07-25/26 is a Saturday and Sunday.
        $this->withSession()->post('/requests/create', $this->withCsrf($this->leavePayload([
            'start_date' => '2026-07-25',
            'end_date'   => '2026-07-26',
        ])))->assertStatus(400);

        // ...and the same holds for a single day that is a public holiday.
        model(HolidayModel::class)->insert(['holiday_date' => '2026-09-02', 'name' => 'National Day']);
        $this->withSession()->post('/requests/create', $this->withCsrf($this->leavePayload([
            'start_date' => '2026-09-02',
        ])))->assertStatus(400);

        $this->assertSame(0, model(LeaveRequestModel::class)->countAdmin([]));
    }

    public function testALateArrivalNotLaterThanTheStartTimeIsRejected(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'earlybird@example.com']));

        $this->withSession()->post('/requests/create', $this->withCsrf([
            'type'          => 'late',
            'start_date'    => '2026-07-22',
            'expected_time' => '08:15',
            'reason'        => 'Traffic',
        ]))->assertStatus(400);
    }

    public function testAnOverlappingRequestIsRejected(): void
    {
        $employee = $this->seedWebUser(['email' => 'overlap@example.com']);
        $this->loginAs($employee);
        $this->seedRequest((int) $employee->id, ['start_date' => '2026-07-20', 'end_date' => '2026-07-24']);

        // Straddles the existing range.
        $this->withSession()->post('/requests/create', $this->withCsrf($this->leavePayload([
            'start_date' => '2026-07-23',
            'end_date'   => '2026-07-27',
        ])))->assertStatus(409);

        // A cancelled request frees the dates again.
        model(LeaveRequestModel::class)->cancelOwn((int) model(LeaveRequestModel::class)->forUser((int) $employee->id)[0]->id, (int) $employee->id);
        $this->withSession()->post('/requests/create', $this->withCsrf($this->leavePayload([
            'start_date' => '2026-07-23',
            'end_date'   => '2026-07-27',
        ])))->assertStatus(200);
    }

    public function testARequestInsideALockedPayrollMonthIsRejected(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'locked@example.com']));
        model(SiteVariableModel::class)->setValue('attendance_locked_through', '2026-07');

        $this->withSession()->post('/requests/create', $this->withCsrf($this->leavePayload()))->assertStatus(409);
    }

    public function testAnEmployeeCanWithdrawOnlyTheirOwnPendingRequest(): void
    {
        $mine  = $this->seedWebUser(['email' => 'mine@example.com']);
        $other = $this->seedWebUser(['email' => 'other@example.com']);
        $this->loginAs($mine);

        $ownRequest   = $this->seedRequest((int) $mine->id);
        $otherRequest = $this->seedRequest((int) $other->id, ['start_date' => '2026-07-23', 'end_date' => '2026-07-23']);

        // Somebody else's request is indistinguishable from a missing one.
        $this->withSession()->post('/requests/cancel/' . $otherRequest->id, $this->withCsrf([]))->assertStatus(404);
        $this->assertSame(LeaveRequestModel::STATUS_PENDING, model(LeaveRequestModel::class)->findRequest((int) $otherRequest->id)->status);

        $this->withSession()->post('/requests/cancel/' . $ownRequest->id, $this->withCsrf([]))->assertStatus(200);
        $this->assertSame(LeaveRequestModel::STATUS_CANCELLED, model(LeaveRequestModel::class)->findRequest((int) $ownRequest->id)->status);

        // Withdrawing twice is refused rather than silently succeeding.
        $this->withSession()->post('/requests/cancel/' . $ownRequest->id, $this->withCsrf([]))->assertStatus(409);
    }

    public function testTheWorkdayPreviewIsScopedToTheCurrentEmployee(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'preview@example.com']));

        $result = $this->withSession()->get('/requests/preview?start_date=2026-07-24&end_date=2026-07-27');

        $result->assertStatus(200);
        // Fri + Mon = 2 workdays; the weekend does not count.
        $this->assertSame(2, json_decode($result->getJSON(), true)['workdays']);
    }

    public function testCreateRequiresCsrf(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'nocsrf@example.com']));

        $result = $this->withSession()->post('/requests/create', $this->leavePayload());

        $this->assertNotSame(200, $result->response()->getStatusCode());
        $this->assertSame(0, model(LeaveRequestModel::class)->countAdmin([]));
    }

    // ------------------------------------------------------------------- approval

    public function testAPlainEmployeeCannotOpenTheApprovalQueue(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'plain@example.com', 'zone' => 'user']));

        $this->withSession()->get('/admin/requests')->assertRedirectTo('/dashboard');
    }

    public function testApprovingRecordsTheReviewerAndNotifiesTheEmployee(): void
    {
        $employee = $this->seedWebUser(['email' => 'waiting@example.com']);
        $manager  = $this->seedWebUser(['email' => 'boss@example.com', 'zone' => 'admin']);
        $request  = $this->seedRequest((int) $employee->id);
        $this->loginAs($manager);

        $this->withSession()->post('/admin/requests/approve/' . $request->id, $this->withCsrf([]))->assertStatus(200);

        $reviewed = model(LeaveRequestModel::class)->findRequest((int) $request->id);
        $this->assertSame(LeaveRequestModel::STATUS_APPROVED, $reviewed->status);
        $this->assertSame((int) $manager->id, (int) $reviewed->reviewed_by);
        $this->assertNotNull($reviewed->reviewed_at);

        // A decision the employee never hears about is barely a decision.
        $notifications = model(NotificationModel::class)->where('user_id', (int) $employee->id)->findAll();
        $this->assertCount(1, $notifications);
        $this->assertStringContainsString('approved', $notifications[0]->message);
    }

    public function testRejectingStoresTheNoteAndNotifies(): void
    {
        $employee = $this->seedWebUser(['email' => 'rejected@example.com']);
        $request  = $this->seedRequest((int) $employee->id);
        $this->loginAs($this->seedWebUser(['email' => 'boss2@example.com', 'zone' => 'admin']));

        $this->withSession()->post('/admin/requests/reject/' . $request->id, $this->withCsrf([
            'review_note' => 'Too many people out that week',
        ]))->assertStatus(200);

        $reviewed = model(LeaveRequestModel::class)->findRequest((int) $request->id);
        $this->assertSame(LeaveRequestModel::STATUS_REJECTED, $reviewed->status);
        $this->assertSame('Too many people out that week', $reviewed->review_note);

        $message = model(NotificationModel::class)->where('user_id', (int) $employee->id)->first()->message;
        $this->assertStringContainsString('rejected', $message);
        $this->assertStringContainsString('Too many people out that week', $message);
    }

    public function testAnAlreadyReviewedRequestCannotBeReviewedAgain(): void
    {
        $employee = $this->seedWebUser(['email' => 'twice@example.com']);
        $request  = $this->seedRequest((int) $employee->id);
        $this->loginAs($this->seedWebUser(['email' => 'boss3@example.com', 'zone' => 'admin']));

        $this->withSession()->post('/admin/requests/approve/' . $request->id, $this->withCsrf([]))->assertStatus(200);
        $this->withSession()->post('/admin/requests/reject/' . $request->id, $this->withCsrf([]))->assertStatus(409);

        $this->assertSame(LeaveRequestModel::STATUS_APPROVED, model(LeaveRequestModel::class)->findRequest((int) $request->id)->status);
    }

    public function testTwoConcurrentApprovalsCannotBothWin(): void
    {
        $employee = $this->seedWebUser(['email' => 'race@example.com']);
        $first    = $this->seedWebUser(['email' => 'boss4@example.com', 'zone' => 'admin']);
        $second   = $this->seedWebUser(['email' => 'boss5@example.com', 'zone' => 'admin']);
        $request  = $this->seedRequest((int) $employee->id);

        // The pending check lives in the UPDATE's WHERE clause, so the second
        // writer loses cleanly instead of overwriting the first decision.
        $model = model(LeaveRequestModel::class);
        $this->assertTrue($model->review((int) $request->id, LeaveRequestModel::STATUS_APPROVED, (int) $first->id, null));
        $this->assertFalse($model->review((int) $request->id, LeaveRequestModel::STATUS_REJECTED, (int) $second->id, null));

        $reviewed = $model->findRequest((int) $request->id);
        $this->assertSame(LeaveRequestModel::STATUS_APPROVED, $reviewed->status);
        $this->assertSame((int) $first->id, (int) $reviewed->reviewed_by);
    }

    public function testApprovalInsideALockedPayrollMonthIsRefused(): void
    {
        $employee = $this->seedWebUser(['email' => 'lockedapprove@example.com']);
        $request  = $this->seedRequest((int) $employee->id);
        model(SiteVariableModel::class)->setValue('attendance_locked_through', '2026-07');
        $this->loginAs($this->seedWebUser(['email' => 'boss6@example.com', 'zone' => 'admin']));

        $this->withSession()->post('/admin/requests/approve/' . $request->id, $this->withCsrf([]))->assertStatus(409);
        $this->assertSame(LeaveRequestModel::STATUS_PENDING, model(LeaveRequestModel::class)->findRequest((int) $request->id)->status);
    }

    public function testApprovingChangesTheAttendanceSheet(): void
    {
        $employee = $this->seedWebUser(['email' => 'sheet@example.com']);
        $request  = $this->seedRequest((int) $employee->id);
        $this->loginAs($this->seedWebUser(['email' => 'boss7@example.com', 'zone' => 'admin']));

        // Before the decision the day is a plain absence.
        $before = (new AttendanceCalendar())->evaluateRange((int) $employee->id, '2026-07-22', '2026-07-22')['2026-07-22'];
        $this->assertSame(AttendanceCalendar::STATUS_ABSENT, $before->status);

        $this->withSession()->post('/admin/requests/approve/' . $request->id, $this->withCsrf([]))->assertStatus(200);

        // After it, the sheet reports leave — this integration is the point of the card.
        $after = (new AttendanceCalendar())->evaluateRange((int) $employee->id, '2026-07-22', '2026-07-22')['2026-07-22'];
        $this->assertSame(AttendanceCalendar::STATUS_ON_LEAVE, $after->status);
    }

    public function testTheQueueFiltersByStatusAndShowsThePendingCount(): void
    {
        $employee = $this->seedWebUser(['email' => 'queued@example.com', 'name' => 'Queued Person']);
        $pending  = $this->seedRequest((int) $employee->id);
        $this->seedRequest((int) $employee->id, [
            'start_date' => '2026-07-23',
            'end_date'   => '2026-07-23',
            'status'     => LeaveRequestModel::STATUS_APPROVED,
            'reason'     => 'Already decided',
        ]);
        $this->loginAs($this->seedWebUser(['email' => 'boss8@example.com', 'zone' => 'admin']));

        $all = $this->withSession()->get('/admin/requests');
        $all->assertStatus(200);
        $all->assertSee('Queued Person');
        $all->assertSee('1 awaiting a decision');

        $onlyApproved = $this->withSession()->get('/admin/requests?status=approved');
        $onlyApproved->assertSee('Already decided');
        $onlyApproved->assertDontSee($pending->reason);
    }

    public function testApproveRequiresCsrf(): void
    {
        $employee = $this->seedWebUser(['email' => 'nocsrf2@example.com']);
        $request  = $this->seedRequest((int) $employee->id);
        $this->loginAs($this->seedWebUser(['email' => 'boss9@example.com', 'zone' => 'admin']));

        $result = $this->withSession()->post('/admin/requests/approve/' . $request->id, []);

        $this->assertNotSame(200, $result->response()->getStatusCode());
        $this->assertSame(LeaveRequestModel::STATUS_PENDING, model(LeaveRequestModel::class)->findRequest((int) $request->id)->status);
    }
}
