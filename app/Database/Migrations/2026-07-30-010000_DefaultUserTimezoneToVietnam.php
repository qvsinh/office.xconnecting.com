<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Changes the `users.timezone` column default from `Asia/Tokyo` to
 * `Asia/Ho_Chi_Minh`.
 *
 * `Asia/Tokyo` came from the starter. This is one office in Vietnam, and
 * `attendance_tz()` has said `Asia/Ho_Chi_Minh` all along — the column default
 * was the one place still disagreeing.
 *
 * Needed as well as the form default because the column default is what applies
 * to inserts that never see the form: `AdminUserSeeder` does not set a timezone,
 * so every admin created with `php spark db:seed` was landing on Tokyo.
 *
 * Existing rows are deliberately left alone — see `down()`.
 */
class DefaultUserTimezoneToVietnam extends Migration
{
    private const COLUMN = [
        'timezone' => [
            'type'       => 'VARCHAR',
            'constraint' => 30,
            'default'    => 'Asia/Ho_Chi_Minh',
        ],
    ];

    public function up(): void
    {
        $this->forge->modifyColumn('users', self::COLUMN);
    }

    /**
     * Restores the old default, and like `up()` does not touch existing rows.
     *
     * Rewriting stored values would be a data change disguised as a schema
     * change: `users.timezone` is a per-person preference, so somebody who
     * deliberately set Tokyo should keep it. Nothing in the app reads this column
     * to render a time anyway — every display goes through `attendance_tz()` or
     * `app_timezone()` — so a stale value is cosmetic.
     */
    public function down(): void
    {
        $this->forge->modifyColumn('users', [
            'timezone' => [
                'type'       => 'VARCHAR',
                'constraint' => 30,
                'default'    => 'Asia/Tokyo',
            ],
        ]);
    }
}
