<?php

use App\Models\AttendanceDayModel;
use App\Models\UserModel;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * `AttendanceDayModel` — day lookups, the correction audit trail, the
 * management filters, and the bulk loaders the today board / monthly report
 * depend on.
 *
 * @internal
 */
final class AttendanceDayModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    private function makeUser(string $name, string $email): int
    {
        return (int) model(UserModel::class)->insert([
            'name'     => $name,
            'email'    => $email,
            'password' => password_hash('secret-password', PASSWORD_DEFAULT),
            'zone'     => 'user',
            'status'   => 1,
        ], true);
    }

    public function testCheckinCreatesTodayRowAndCheckoutOverwritesLastTime(): void
    {
        $model  = new AttendanceDayModel();
        $userId = $this->makeUser('Employee One', 'one@example.com');

        $row = $model->checkin($userId, '10.0.0.5', '2026-07-22', '2026-07-22 01:30:00');

        $this->assertSame('2026-07-22', $row->work_date);
        $this->assertSame('2026-07-22 01:30:00', $row->checkin_at);
        $this->assertSame('10.0.0.5', $row->checkin_ip);
        $this->assertNull($row->checkout_at);

        $model->checkout((int) $row->id, '10.0.0.5', '2026-07-22 05:00:00');
        // A second check-out wins — an accidental lunchtime tap is recoverable.
        $updated = $model->checkout((int) $row->id, '10.0.0.6', '2026-07-22 11:00:00');

        $this->assertNotNull($updated);
        $this->assertSame('2026-07-22 11:00:00', $updated->checkout_at);
        $this->assertSame('10.0.0.6', $updated->checkout_ip);
        $this->assertSame(0, (int) $updated->missing_checkout);
    }

    public function testUniqueKeyBlocksASecondRowForTheSameUserAndDay(): void
    {
        $model  = new AttendanceDayModel();
        $userId = $this->makeUser('Employee One', 'one@example.com');

        $model->checkin($userId, '10.0.0.5', '2026-07-22', '2026-07-22 01:30:00');

        $this->expectException(DatabaseException::class);
        $model->checkin($userId, '10.0.0.5', '2026-07-22', '2026-07-22 02:00:00');
    }

    public function testDayForAndTodayForAreScopedToTheOwner(): void
    {
        $model = new AttendanceDayModel();
        $mine  = $this->makeUser('Mine', 'mine@example.com');
        $other = $this->makeUser('Other', 'other@example.com');

        $model->checkin($other, '10.0.0.9', '2026-07-22', '2026-07-22 01:00:00');

        $this->assertNull($model->dayFor($mine, '2026-07-22'));
        $this->assertNotNull($model->dayFor($other, '2026-07-22'));
    }

    public function testApplyCorrectionRecordsEditorAndKeepsTheFirstOriginalValues(): void
    {
        $model  = new AttendanceDayModel();
        $userId = $this->makeUser('Employee One', 'one@example.com');
        $editor = $this->makeUser('Manager', 'manager@example.com');

        $row = $model->checkin($userId, '10.0.0.5', '2026-07-22', '2026-07-22 02:15:00');
        $model->checkout((int) $row->id, '10.0.0.5', '2026-07-22 11:00:00');

        $model->applyCorrection((int) $row->id, [
            'checkin_at' => '2026-07-22 01:30:00',
            'note'       => 'Badge reader was down',
        ], $editor, '2026-07-23 03:00:00');

        $corrected = $model->findDay((int) $row->id);
        $this->assertSame('2026-07-22 01:30:00', $corrected->checkin_at);
        $this->assertSame('Badge reader was down', $corrected->note);
        $this->assertSame($editor, (int) $corrected->edited_by);
        $this->assertSame('2026-07-23 03:00:00', $corrected->edited_at);
        // The value the employee actually recorded is preserved.
        $this->assertSame('2026-07-22 02:15:00', $corrected->original_checkin_at);
        $this->assertSame('2026-07-22 11:00:00', $corrected->original_checkout_at);

        // A second correction must not overwrite the originals.
        $model->applyCorrection((int) $row->id, ['checkin_at' => '2026-07-22 01:45:00'], $editor, '2026-07-24 03:00:00');

        $twice = $model->findDay((int) $row->id);
        $this->assertSame('2026-07-22 01:45:00', $twice->checkin_at);
        $this->assertSame('2026-07-22 02:15:00', $twice->original_checkin_at);
        $this->assertSame('2026-07-24 03:00:00', $twice->edited_at);
    }

    public function testApplyCorrectionReturnsFalseForUnknownRow(): void
    {
        $this->assertFalse((new AttendanceDayModel())->applyCorrection(999999, ['note' => 'x'], 1, '2026-07-23 03:00:00'));
    }

    public function testCorrectingACheckoutClearsTheMissingCheckoutFlag(): void
    {
        $model  = new AttendanceDayModel();
        $userId = $this->makeUser('Employee One', 'one@example.com');
        $editor = $this->makeUser('Manager', 'manager@example.com');

        $row = $model->checkin($userId, '10.0.0.5', '2026-07-22', '2026-07-22 01:30:00');
        $model->flagMissingCheckout([(int) $row->id]);
        $this->assertSame(1, (int) $model->findDay((int) $row->id)->missing_checkout);

        $model->applyCorrection((int) $row->id, ['checkout_at' => '2026-07-22 11:00:00'], $editor, '2026-07-23 03:00:00');

        $this->assertSame(0, (int) $model->findDay((int) $row->id)->missing_checkout);
    }

    public function testSearchAdminFiltersByUserDateRangeAndMissingCheckout(): void
    {
        $model = new AttendanceDayModel();
        $alice = $this->makeUser('Alice', 'alice@example.com');
        $bob   = $this->makeUser('Bob', 'bob@example.com');

        $model->checkin($alice, '10.0.0.1', '2026-07-20', '2026-07-20 01:30:00');
        $aliceOpen = $model->checkin($alice, '10.0.0.1', '2026-07-22', '2026-07-22 01:30:00');
        $model->checkin($bob, '10.0.0.2', '2026-07-22', '2026-07-22 01:40:00');
        $model->flagMissingCheckout([(int) $aliceOpen->id]);

        $this->assertCount(3, $model->searchAdmin([]));
        $this->assertSame(3, $model->countAdmin([]));

        $this->assertCount(2, $model->searchAdmin(['user_id' => $alice]));
        $this->assertSame(2, $model->countAdmin(['user_id' => $alice]));

        $ranged = $model->searchAdmin(['date_from' => '2026-07-21', 'date_to' => '2026-07-22']);
        $this->assertCount(2, $ranged);
        $this->assertSame(2, $model->countAdmin(['date_from' => '2026-07-21', 'date_to' => '2026-07-22']));

        $flagged = $model->searchAdmin(['missing_checkout' => '1']);
        $this->assertCount(1, $flagged);
        $this->assertSame((int) $aliceOpen->id, (int) $flagged[0]->id);
    }

    public function testSearchAdminJoinsTheEmployeeNameAndIgnoresAnUnknownSortColumn(): void
    {
        $model = new AttendanceDayModel();
        $alice = $this->makeUser('Alice', 'alice@example.com');

        $model->checkin($alice, '10.0.0.1', '2026-07-22', '2026-07-22 01:30:00');

        // An arbitrary sort column must not reach SQL — it falls back silently.
        $rows = $model->searchAdmin([], 'work_date; DROP TABLE users', 'asc');

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]->user_name);
    }

    public function testHistoryForIsNewestFirstAndScopedToTheOwner(): void
    {
        $model = new AttendanceDayModel();
        $alice = $this->makeUser('Alice', 'alice@example.com');
        $bob   = $this->makeUser('Bob', 'bob@example.com');

        $model->checkin($alice, '10.0.0.1', '2026-07-20', '2026-07-20 01:30:00');
        $model->checkin($alice, '10.0.0.1', '2026-07-22', '2026-07-22 01:30:00');
        $model->checkin($bob, '10.0.0.2', '2026-07-22', '2026-07-22 01:40:00');

        $history = $model->historyFor($alice);

        $this->assertCount(2, $history);
        $this->assertSame('2026-07-22', $history[0]->work_date);
        $this->assertSame(2, $model->countForUser($alice));
    }

    public function testForUsersInRangeIsBoundedAndEmptyForNoUsers(): void
    {
        $model = new AttendanceDayModel();
        $alice = $this->makeUser('Alice', 'alice@example.com');
        $bob   = $this->makeUser('Bob', 'bob@example.com');

        $model->checkin($alice, '10.0.0.1', '2026-07-20', '2026-07-20 01:30:00');
        $model->checkin($alice, '10.0.0.1', '2026-07-22', '2026-07-22 01:30:00');
        $model->checkin($bob, '10.0.0.2', '2026-07-22', '2026-07-22 01:40:00');

        $this->assertCount(3, $model->forUsersInRange([$alice, $bob], '2026-07-01', '2026-07-31'));
        $this->assertCount(2, $model->forUsersInRange([$alice], '2026-07-01', '2026-07-31'));
        $this->assertCount(2, $model->forUsersInRange([$alice, $bob], '2026-07-22', '2026-07-22'));
        $this->assertSame([], $model->forUsersInRange([], '2026-07-01', '2026-07-31'));
    }

    public function testOpenRowsOnFindsOnlyUnflaggedArrivalsWithoutDeparture(): void
    {
        $model = new AttendanceDayModel();
        $alice = $this->makeUser('Alice', 'alice@example.com');
        $bob   = $this->makeUser('Bob', 'bob@example.com');
        $cara  = $this->makeUser('Cara', 'cara@example.com');

        $open      = $model->checkin($alice, '10.0.0.1', '2026-07-22', '2026-07-22 01:30:00');
        $completed = $model->checkin($bob, '10.0.0.2', '2026-07-22', '2026-07-22 01:40:00');
        $model->checkout((int) $completed->id, '10.0.0.2', '2026-07-22 11:00:00');
        $alreadyFlagged = $model->checkin($cara, '10.0.0.3', '2026-07-22', '2026-07-22 01:50:00');
        $model->flagMissingCheckout([(int) $alreadyFlagged->id]);

        $rows = $model->openRowsOn('2026-07-22');

        $this->assertCount(1, $rows);
        $this->assertSame((int) $open->id, (int) $rows[0]->id);
        $this->assertSame(0, $model->flagMissingCheckout([]));
    }
}
