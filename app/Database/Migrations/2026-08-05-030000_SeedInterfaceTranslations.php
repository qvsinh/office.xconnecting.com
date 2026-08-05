<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Database;

/**
 * Seeds the interface strings for the views, in English and Vietnamese.
 *
 * `location` is the group and `slug` the key, so `lang('common.date')` is answered
 * by a row `('vi', 'common', 'date', 'Ngày')`. `common` holds anything used on more
 * than one screen, so rewording it lands everywhere at once.
 *
 * **A slug is globally unique**, not unique per group: the table carries
 * `UNIQUE(lang_code, slug)` and `TranslationModel::existsForLangSlug()` matches the
 * same way. So `date` may live in exactly one group, which is a feature — it stops
 * the same word being translated three different ways on three screens. A slug that
 * collides with an existing one means "reuse that key", or rename, as the page
 * headings here do against the `nav` labels.
 *
 * English rows are seeded as well as Vietnamese ones. That is the point: an
 * administrator can reword the English UI from `/admin/translations` without a
 * deploy, and `TranslationCoverageTest` fails if a key a view uses is missing from
 * either language.
 *
 * Idempotent per row, so it is safe on a live database and re-runnable.
 */
class SeedInterfaceTranslations extends Migration
{
    /**
     * location => slug => [en, vi]
     */
    private const STRINGS = [
        'attendance' => [
            'already_out'               => ['Checked out already — pressing again replaces the time.', 'Đã chấm ra — bấm lại sẽ ghi đè giờ cũ.'],
            'back_to_checkin'           => ['Back to check-in', 'Về trang chấm công'],
            'blocked_impersonating'     => ['You are acting as another user. Stop acting as them to record your own attendance.', 'Bạn đang thao tác thay người khác. Hãy thoát chế độ đó để tự chấm công.'],
            'blocked_offsite'           => ['You are not on the office network, so attendance cannot be recorded from here.', 'Bạn không ở trong mạng công ty nên không thể chấm công từ đây.'],
            'check_in'                  => ['Check in', 'Chấm vào'],
            'check_out'                 => ['Check out', 'Chấm ra'],
            'checked_in'                => ['Checked in', 'Đã vào'],
            'checked_out'               => ['Checked out', 'Đã ra'],
            'checkin_col'               => ['Check-in', 'Giờ vào'],
            'checkin_failed'            => ['Could not check you in.', 'Không chấm vào được.'],
            'checking_in'               => ['Checking in…', 'Đang chấm vào…'],
            'checkout_col'              => ['Check-out', 'Giờ ra'],
            'checkout_failed'           => ['Could not check you out.', 'Không chấm ra được.'],
            'corrected'                 => ['corrected', 'đã sửa'],
            'empty_history'             => ['No attendance recorded yet. Your first check-in will show up here.', 'Chưa có dữ liệu chấm công. Lần chấm vào đầu tiên sẽ xuất hiện ở đây.'],
            'empty_month'               => ['Nothing recorded for this month yet.', 'Tháng này chưa có dữ liệu.'],
            'expected'                  => ['Expected', 'Dự kiến'],
            'history'                   => ['History', 'Lịch sử'],
            'in_col'                    => ['In', 'Vào'],
            'intro'                     => ['Check in when you arrive and check out when you leave. Times shown in Vietnam time (UTC+7).', 'Chấm vào khi bạn đến và chấm ra khi bạn về. Giờ hiển thị theo giờ Việt Nam (UTC+7).'],
            'missing'                   => ['missing', 'thiếu'],
            'missing_checkout_notice'   => ['No check-out was recorded for this day. Check out now, or ask your manager to correct it.', 'Ngày này chưa có giờ ra. Hãy chấm ra ngay, hoặc nhờ quản lý sửa lại.'],
            'on_allowlist'              => ['Your address is on the office network allowlist.', 'Địa chỉ của bạn nằm trong danh sách mạng công ty.'],
            'ot'                        => ['OT', 'Ngoài giờ'],
            'out_col'                   => ['Out', 'Ra'],
            'overtime'                  => ['Overtime', 'Ngoài giờ'],
            'report_title'              => ['My month', 'Tháng của tôi'],
            'title'                     => ['My attendance', 'Chấm công của tôi'],
            'unavailable_here'          => ['Unavailable here', 'Không khả dụng ở đây'],
            'update_checkout'           => ['Update check-out', 'Cập nhật giờ ra'],
        ],
        'common' => [
            'actions'         => ['Actions', 'Thao tác'],
            'any'             => ['Any', 'Tất cả'],
            'cancel'          => ['Cancel', 'Huỷ'],
            'clear'           => ['Clear', 'Xoá lọc'],
            'close'           => ['Close', 'Đóng'],
            'date'            => ['Date', 'Ngày'],
            'dates'           => ['Dates', 'Khoảng ngày'],
            'day'             => ['Day', 'Thứ'],
            'days'            => ['Days', 'Số ngày'],
            'from'            => ['From', 'Từ'],
            'month'           => ['Month', 'Tháng'],
            'network_error'   => ['Network error. Please try again.', 'Lỗi kết nối. Vui lòng thử lại.'],
            'next'            => ['Next', 'Sau'],
            'note'            => ['Note', 'Ghi chú'],
            'of'              => ['of', '/'],
            'page_of'         => ['Page', 'Trang'],
            'prev'            => ['Prev', 'Trước'],
            'reason'          => ['Reason', 'Lý do'],
            'rows_per_page'   => ['Rows per page', 'Số dòng mỗi trang'],
            'saving'          => ['Saving…', 'Đang lưu…'],
            'search'          => ['Search', 'Tìm'],
            'show'            => ['Show', 'Xem'],
            'status'          => ['Status', 'Trạng thái'],
            'submit'          => ['Submit', 'Gửi'],
            'submitting'      => ['Submitting…', 'Đang gửi…'],
            'to'              => ['To', 'Đến'],
            'total'           => ['total', 'tổng'],
            'worked'          => ['Worked', 'Đã làm'],
        ],
        'requests' => [
            'afternoon_only'     => ['Afternoon only', 'Chỉ buổi chiều'],
            'annual'             => ['Annual (uses your balance)', 'Phép năm (trừ vào quỹ phép)'],
            'annual_left'        => ['Annual leave left', 'Phép năm còn lại'],
            'backdated'          => ['backdated', 'lùi ngày'],
            'day_portion'        => ['How much of the day?', 'Nghỉ bao nhiêu trong ngày?'],
            'expected_arrival'   => ['Expected arrival', 'Giờ đến dự kiến'],
            'leave_type'         => ['Leave type', 'Loại đơn'],
            'morning_only'       => ['Morning only', 'Chỉ buổi sáng'],
            'new_request'        => ['New request', 'Tạo đơn'],
            'sick'               => ['Sick', 'Nghỉ bệnh'],
            'submit_failed'      => ['Could not submit the request.', 'Không gửi được đơn.'],
            'title'              => ['My requests', 'Đơn của tôi'],
            'to_optional'        => ['To (optional)', 'Đến (không bắt buộc)'],
            'type'               => ['Type', 'Loại'],
            'unpaid'             => ['Unpaid', 'Nghỉ không lương'],
            'whole_day'          => ['Whole day', 'Cả ngày'],
            'withdraw'           => ['Withdraw', 'Rút đơn'],
            'withdraw_failed'    => ['Could not withdraw the request.', 'Không rút được đơn.'],
        ],
        'status' => [
            'absent'         => ['Absent', 'Vắng'],
            'approved'       => ['Approved', 'Đã duyệt'],
            'holiday'        => ['Holiday', 'Ngày lễ'],
            'late'           => ['Late', 'Trễ'],
            'late_excused'   => ['late excused', 'trễ có phép'],
            'no_checkout'    => ['No check-out', 'Chưa chấm ra'],
            'non_working'    => ['Non-working', 'Ngoài giờ làm'],
            'not_in_yet'     => ['Not in yet', 'Chưa vào'],
            'on_leave'       => ['On leave', 'Nghỉ phép'],
            'pending'        => ['Pending', 'Chờ duyệt'],
            'present'        => ['Present', 'Có mặt'],
            'rejected'       => ['Rejected', 'Bị từ chối'],
            'unknown'        => ['Unknown', 'Không rõ'],
            'withdrawn'      => ['Withdrawn', 'Đã rút'],
        ],
    ];

    public function up(): void
    {
        $db = Database::connect($this->DBGroup);
        $db->resetDataCache();

        if (! $db->tableExists('translation')) {
            return;
        }

        foreach (self::STRINGS as $location => $strings) {
            foreach ($strings as $slug => [$en, $vi]) {
                foreach (['en' => $en, 'vi' => $vi] as $lang => $content) {
                    $exists = $db->table('translation')
                        ->where('lang_code', $lang)
                        ->where('location', $location)
                        ->where('slug', $slug)
                        ->countAllResults() > 0;

                    if ($exists) {
                        continue;
                    }

                    $db->table('translation')->insert([
                        'lang_code' => $lang,
                        'location'  => $location,
                        'slug'      => $slug,
                        'content'   => $content,
                    ]);
                }
            }
        }
    }

    /**
     * Safe-on-live guard: only delete on the dedicated tests DB group, so a
     * rollback cannot throw away strings an administrator has edited.
     */
    public function down(): void
    {
        $testsDatabase = (string) (config(Database::class)->tests['database'] ?? '');

        if ($testsDatabase !== '' && $this->db->getDatabase() === $testsDatabase) {
            $this->db->table('translation')->whereIn('location', array_keys(self::STRINGS))->delete();
        }
    }
}
