<?php

namespace App\Libraries;

use App\Models\AttendanceDayModel;
use App\Models\ScheduledTaskModel;
use CodeIgniter\I18n\Time;
use Throwable;

/**
 * Fixed handler allowlist + the single shared dispatch path (`runTask()`)
 * used by both `php spark schedule:run` and `Admin\ScheduledTasks::runNow()`.
 *
 * CRITICAL: a `scheduled_tasks` row's `handler` column is only ever looked up
 * as a KEY in `registry()` below — never reflected into a class/method name
 * from the DB row. The legacy `Cli\Tasks::run_tasks()` did
 * `new ("App\Libraries\Tasks\" . $row['lib_name'])->{$row['method']}()`
 * straight from an admin-editable row, i.e. stored-value -> arbitrary
 * class/method instantiation. Adding a handler here means a code change, not
 * a DB row — that's the point (plan §4/§7).
 */
class ScheduledTaskRunner
{
    /**
     * Bound length for `last_result` — mirrors `ScheduledTaskModel`'s own
     * truncation (belt-and-braces; the model is the single source of truth
     * for the stored length, this just keeps returned envelopes sane too).
     */
    private const RESULT_MAX_LENGTH = 5000;

    /**
     * @return array<string, array{label: string, run: callable(array<string, mixed>): string}>
     */
    public function registry(): array
    {
        return [
            'emails.purge' => [
                'label' => 'Purge old sent emails',
                'run'   => fn (array $params): string => $this->purgeTable('emails', 'created_at', $this->days($params, 7)),
            ],
            'notifications.purge' => [
                'label' => 'Purge old notifications',
                'run'   => fn (array $params): string => $this->purgeTable('notifications', 'created_at', $this->days($params, 30)),
            ],
            'access_logs.purge' => [
                'label' => 'Purge old access logs',
                // Personal data (addresses, devices) — 90 days is long enough to
                // investigate a dispute and short enough not to keep a permanent
                // movement record of every employee.
                'run' => fn (array $params): string => $this->purgeTable('access_logs', 'created_at', $this->days($params, 90)),
            ],
            'attendance.flag_missing_checkout' => [
                'label' => 'Flag attendance days with no check-out',
                'run'   => fn (array $params): string => $this->flagMissingCheckouts($params),
            ],
            'attendance.remind_checkout' => [
                'label' => 'Remind employees who have not checked out',
                'run'   => static fn (array $params): string => (new AttendanceReminders())->remindCheckout($params),
            ],
            'attendance.absence_digest' => [
                'label' => 'Daily absence digest for managers',
                'run'   => static fn (array $params): string => (new AttendanceReminders())->absenceDigest($params),
            ],
            'attendance.missing_checkout_digest' => [
                'label' => 'Weekly uncorrected missing check-outs',
                'run'   => static fn (array $params): string => (new AttendanceReminders())->missingCheckoutDigest($params),
            ],
            'requests.pending_digest' => [
                'label' => 'Pending leave requests digest',
                'run'   => static fn (array $params): string => (new AttendanceReminders())->pendingRequestsDigest($params),
            ],
        ];
    }

    /**
     * Marks yesterday's (Vietnam calendar) attendance rows that have an arrival
     * but no departure, so the management list can surface them for correction.
     *
     * Deliberately does NOT invent a check-out time: auto-filling the schedule's
     * end time would fabricate payroll data. Flagging puts a human in the loop
     * through the audited correction path instead.
     *
     * `params.days_ago` (default 1) lets an admin re-scan an older day from the
     * scheduled-tasks screen without a code change.
     *
     * @param array<string, mixed> $params
     */
    private function flagMissingCheckouts(array $params): string
    {
        helper('attendance');

        $daysAgo  = max(0, (int) ($params['days_ago'] ?? 1));
        $workDate = Time::now(attendance_tz())->subDays($daysAgo)->toDateString();

        $model = model(AttendanceDayModel::class);
        $rows  = $model->openRowsOn($workDate);

        if ($rows === []) {
            return "No open attendance rows on {$workDate}.";
        }

        $flagged = $model->flagMissingCheckout(array_map(static fn (object $row): int => (int) $row->id, $rows));

        return "Flagged {$flagged} day(s) without check-out on {$workDate}.";
    }

    /**
     * Allowlist keys only — feeds the `handler` `in_list[...]` validation
     * rule in `Admin\ScheduledTasks` and the view's handler dropdown.
     *
     * @return list<string>
     */
    public function handlerKeys(): array
    {
        return array_keys($this->registry());
    }

    /**
     * Single shared dispatch path for one due (or manually run) task. Never
     * rethrows and never leaves the row stuck in `running`: an unknown
     * handler key or a handler that throws both end in `last_status='error'`
     * with the failure recorded, so a bad task never aborts the batch.
     *
     * @return array{status: string, result: string}
     */
    public function runTask(object $task): array
    {
        $model = model(ScheduledTaskModel::class);
        $model->markRunning((int) $task->id);

        $start    = microtime(true);
        $registry = $this->registry();

        if (! isset($registry[$task->handler])) {
            $status = 'error';
            $result = "Unknown handler: {$task->handler}";
        } else {
            try {
                $result = (string) ($registry[$task->handler]['run'])($this->decodeParams($task->params ?? null));
                $status = 'success';
            } catch (Throwable $e) {
                $status = 'error';
                $result = $e->getMessage();
            }
        }

        $durationMs = (int) round((microtime(true) - $start) * 1000);
        $nextRunAt  = date('Y-m-d H:i:s', time() + ((int) $task->interval_minutes * 60));

        $model->recordResult((int) $task->id, $status, $result, $durationMs, $nextRunAt);

        return ['status' => $status, 'result' => mb_substr($result, 0, self::RESULT_MAX_LENGTH)];
    }

    /**
     * Decodes the task's `params` JSON. Invalid/empty JSON yields an empty
     * array rather than throwing — a malformed `params` value must not crash
     * the batch (it isn't code, so there's nothing to "reject" here besides
     * falling back to handler defaults).
     *
     * @return array<string, mixed>
     */
    private function decodeParams(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function days(array $params, int $default): int
    {
        $days = (int) ($params['days'] ?? $default);

        return $days > 0 ? $days : $default;
    }

    /**
     * Bounded hard DELETE of rows older than `$days` from `$table` — ports
     * the legacy `Cleanup::cleanup_emails()`/`clean_up_users_login_log()`
     * pattern (raw query builder DELETE, no model/soft-delete indirection) to
     * every purge handler here, so a purge job actually reclaims storage
     * regardless of a table's own soft-delete convention.
     *
     * ponytail: a plain single-statement DELETE — `emails`/`notifications`
     * are maintenance-log tables pruned to a bounded recent window, not
     * multi-million-row tables needing chunked deletes. Revisit with a
     * chunked delete pattern if row counts grow large enough to make one
     * DELETE a lock/replication concern.
     */
    private function purgeTable(string $table, string $dateColumn, int $days): string
    {
        $db     = db_connect();
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        $count = $db->table($table)->where("{$dateColumn} <", $cutoff)->countAllResults();

        if ($count > 0) {
            $db->table($table)->where("{$dateColumn} <", $cutoff)->delete();
        }

        return "Purged {$count} row(s) from {$table} older than {$days} day(s).";
    }
}
