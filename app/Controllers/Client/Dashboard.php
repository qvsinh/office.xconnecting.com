<?php

namespace App\Controllers\Client;

use App\Controllers\BaseController;
use App\Libraries\AttendanceCalendar;
use App\Libraries\LeaveBalance;
use App\Models\AttendanceDayModel;
use App\Models\LeaveRequestModel;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Personal dashboard.
 *
 * Previously summarised the signed-in user's workspaces/projects/tasks; that
 * whole module was removed (this is an attendance app), so the page now answers
 * what an employee actually opens it for: am I checked in, how is my month going,
 * how much leave is left.
 *
 * Every figure comes from `AttendanceCalendar` / `LeaveBalance`, so the dashboard
 * can never disagree with the attendance pages.
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
        $userId   = current_user_id();
        $month    = substr(attendance_today(), 0, 7);
        $calendar = new AttendanceCalendar();

        $days = $calendar->evaluateMonth([$userId], $month)[$userId] ?? [];

        return view('client/dashboard', [
            'today'           => model(AttendanceDayModel::class)->todayFor($userId),
            'todayDate'       => attendance_today(),
            'month'           => $month,
            'summary'         => $calendar->summarise($days),
            'balance'         => (new LeaveBalance())->summary($userId),
            'pendingRequests' => model(LeaveRequestModel::class)
                ->where('user_id', $userId)
                ->where('status', LeaveRequestModel::STATUS_PENDING)
                ->countAllResults(),
        ]);
    }
}
