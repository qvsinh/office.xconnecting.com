<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Seeds the navigation and header strings in English and Vietnamese, so the
 * language switcher has something to switch and `/admin/translations` opens with
 * a worked example instead of an empty table.
 *
 * `location` is CI4's language-file part and `slug` is the key, so a row
 * ('vi', 'nav', 'dashboard', 'Trang chủ') answers `lang('nav.dashboard')`.
 *
 * English rows are seeded too, even though they duplicate the literal text in the
 * templates. That is the point: an administrator can reword the English UI from
 * `/admin/translations` without a deploy, and a missing row falls back to the
 * file-based strings rather than showing a raw key.
 *
 * Idempotent per row, so it is safe on a live database and re-runnable.
 */
class SeedNavTranslations extends Migration
{
    /**
     * slug => [en, vi]
     */
    private const NAV = [
        // Groups
        'attendance'      => ['Attendance', 'Chấm công'],
        'account'         => ['Account', 'Tài khoản'],
        'admin'           => ['Admin', 'Quản trị'],
        'management'      => ['Management', 'Quản lý'],

        // Employee
        'my_attendance'   => ['My Attendance', 'Chấm công của tôi'],
        'my_month'        => ['My Month', 'Tháng của tôi'],
        'my_requests'     => ['My Requests', 'Đơn của tôi'],

        // Account
        'profile'         => ['Profile', 'Thông tin cá nhân'],
        'security'        => ['Security', 'Bảo mật'],
        'notifications'   => ['Notifications', 'Thông báo'],
        'api_keys'        => ['API Keys', 'Khoá API'],

        // Attendance management
        'att_today'       => ['Attendance · Today', 'Chấm công · Hôm nay'],
        'att_all'         => ['Attendance · All employees', 'Chấm công · Toàn bộ nhân viên'],
        'att_report'      => ['Attendance · Monthly report', 'Chấm công · Báo cáo tháng'],
        'att_requests'    => ['Attendance · Approve requests', 'Chấm công · Duyệt đơn'],
        'att_schedules'   => ['Attendance · Work schedules', 'Chấm công · Lịch làm việc'],
        'att_holidays'    => ['Attendance · Holidays', 'Chấm công · Ngày lễ'],

        // Admin
        'users'           => ['Users', 'Người dùng'],
        'groups'          => ['Groups', 'Nhóm'],
        'settings'        => ['Settings', 'Cài đặt'],
        'translations'    => ['Translations', 'Bản dịch'],
        'email_templates' => ['Email Templates', 'Mẫu email'],
        'emails'          => ['Emails', 'Email'],
        'access_log'      => ['Access log', 'Lịch sử truy cập'],
        'scheduled_tasks' => ['Scheduled Tasks', 'Tác vụ định kỳ'],
        'system_info'     => ['System info', 'Thông tin hệ thống'],
        'migrations'      => ['Migrations', 'Migration'],
        'logs'            => ['Logs', 'Log hệ thống'],

        'dashboard'       => ['Dashboard', 'Trang chủ'],
        'docs'            => ['Docs', 'Tài liệu'],
    ];

    /**
     * Header and shared chrome.
     */
    private const UI = [
        'admin_console'  => ['Admin console', 'Bảng quản trị'],
        'client_console' => ['Client console', 'Bảng người dùng'],
        'log_out'        => ['Log out', 'Đăng xuất'],
        'language'       => ['Language', 'Ngôn ngữ'],
        'toggle_sidebar' => ['Toggle sidebar', 'Ẩn/hiện thanh bên'],
        'toggle_dark'    => ['Toggle dark mode', 'Chế độ tối'],
        'mark_all_read'  => ['Mark all read', 'Đánh dấu đã đọc'],
        'view_all'       => ['View all', 'Xem tất cả'],
        'loading'        => ['Loading…', 'Đang tải…'],
        'no_notifications' => ['No notifications yet.', 'Chưa có thông báo.'],
    ];

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('translation')) {
            return;
        }

        $rows = [];

        foreach (['nav' => self::NAV, 'ui' => self::UI] as $location => $strings) {
            foreach ($strings as $slug => [$en, $vi]) {
                $rows[] = ['lang_code' => 'en', 'location' => $location, 'slug' => $slug, 'content' => $en];
                $rows[] = ['lang_code' => 'vi', 'location' => $location, 'slug' => $slug, 'content' => $vi];
            }
        }

        foreach ($rows as $row) {
            $exists = $db->table('translation')
                ->where('lang_code', $row['lang_code'])
                ->where('location', $row['location'])
                ->where('slug', $row['slug'])
                ->countAllResults() > 0;

            if ($exists) {
                continue;
            }

            // No timestamp columns on this table.
            $db->table('translation')->insert($row);
        }
    }

    /**
     * Safe-on-live guard: only delete on the dedicated tests DB group, so a
     * rollback cannot throw away translations an administrator has edited.
     */
    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->db->table('translation')->whereIn('location', ['nav', 'ui'])->delete();
        }
    }
}
