<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\AttendanceCalendar;
use App\Models\GroupModel;
use App\Models\LeaveRequestModel;
use App\Models\UserModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Company-wide admin dashboard.
 *
 * The workspaces/projects/tasks widgets were removed along with that module;
 * what a manager needs at a glance here is the same thing the today board shows —
 * who is in, who is late, who is missing — plus the counts that still exist
 * (users, groups) and how many leave requests are waiting on a decision.
 *
 * Status counts come from `AttendanceCalendar` (one bulk evaluation), so they
 * always agree with `/admin/attendance/today`.
 */
class Dashboard extends BaseController
{
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger): void
    {
        parent::initController($request, $response, $logger);
        helper(['auth', 'attendance']);
    }

    public function index(): string
    {
        $date     = attendance_today();
        $roster   = model(UserModel::class)->activeRoster();
        $userIds  = array_map(static fn (object $user): int => (int) $user->id, $roster);
        $calendar = new AttendanceCalendar();

        $evaluations = $calendar->evaluateSpan($userIds, $date, $date);
        $counts      = array_fill_keys([
            AttendanceCalendar::STATUS_PRESENT,
            AttendanceCalendar::STATUS_LATE,
            AttendanceCalendar::STATUS_NOT_YET,
            AttendanceCalendar::STATUS_ABSENT,
            AttendanceCalendar::STATUS_ON_LEAVE,
            AttendanceCalendar::STATUS_MISSING_CHECKOUT,
        ], 0);

        foreach ($userIds as $userId) {
            // Nobody is absent before their start time — same softening the today
            // board applies, so the two screens can't disagree.
            $status = $calendar->withPendingArrival($userId, $date, $evaluations[$userId][$date])->status;

            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return view('admin/dashboard', [
            'date'   => $date,
            'counts' => $counts,
            'stats'  => [
                'employees'        => count($roster),
                'groups'           => model(GroupModel::class)->countAll(),
                'users'            => model(UserModel::class)->countAdmin(['status' => 1]),
                'pending_requests' => model(LeaveRequestModel::class)->countPending(),
            ],
        ]);
    }
}
