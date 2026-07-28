<?php

use App\Models\HolidayModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Holiday management (`Admin\Holidays`) through real routing + filters + CSRF:
 * permission gating, the bulk paste parser, duplicate handling, and the
 * rejection of dates that don't exist (a bogus holiday would mark a working day
 * off for the whole company).
 *
 * @internal
 */
final class AdminHolidaysTest extends CIUnitTestCase
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

    private function loginAsManager(string $email = 'boss@example.com'): void
    {
        $this->loginAs($this->seedWebUser(['email' => $email, 'zone' => 'admin']));
    }

    public function testPlainEmployeeCannotOpenThePage(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'plain@example.com', 'zone' => 'user']));

        $this->withSession()->get('/admin/attendance/holidays')->assertRedirectTo('/dashboard');
    }

    public function testPageRendersAndWarnsWhenAYearHasNoHolidays(): void
    {
        $this->loginAsManager();

        $result = $this->withSession()->get('/admin/attendance/holidays?year=2026');

        $result->assertStatus(200);
        $result->assertSee('Holidays');
        $result->assertSee('No holidays recorded for 2026');
    }

    public function testBulkAddStoresEveryLine(): void
    {
        $this->loginAsManager();

        $result = $this->withSession()->post('/admin/attendance/holidays/create', $this->withCsrf([
            'bulk' => "2026-01-01 New Year's Day\n2026-04-30 Reunification Day\n2026-09-02 National Day",
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        $this->assertSame(3, $json['added']);
        $this->assertSame(0, $json['skipped']);

        $rows = model(HolidayModel::class)->forYear(2026);
        $this->assertCount(3, $rows);
        $this->assertSame('2026-01-01', $rows[0]->holiday_date);
        $this->assertSame("New Year's Day", $rows[0]->name);
    }

    public function testDuplicateDatesAreReportedNotDuplicatedAndDoNotFailTheBatch(): void
    {
        $this->loginAsManager();
        model(HolidayModel::class)->insert(['holiday_date' => '2026-09-02', 'name' => 'National Day']);

        $result = $this->withSession()->post('/admin/attendance/holidays/create', $this->withCsrf([
            'bulk' => "2026-09-02 National Day\n2026-09-03 Bridge day",
        ]));

        $result->assertStatus(200);
        $json = json_decode($result->getJSON(), true);
        // The new date lands, the existing one is left alone — no unique-key 500.
        $this->assertSame(1, $json['added']);
        $this->assertSame(1, $json['skipped']);
        $this->assertCount(2, model(HolidayModel::class)->forYear(2026));
    }

    public function testAnImpossibleDateIsRejected(): void
    {
        $this->loginAsManager();

        $this->withSession()->post('/admin/attendance/holidays/create', $this->withCsrf([
            'bulk' => '2026-02-30 Not a day',
        ]))->assertStatus(400);

        $this->assertCount(0, model(HolidayModel::class)->forYear(2026));
    }

    public function testAnUnparseableLineIsRejectedWholesale(): void
    {
        $this->loginAsManager();

        // One bad line fails the batch rather than silently importing a subset —
        // a partially-applied holiday list is worse than none.
        $this->withSession()->post('/admin/attendance/holidays/create', $this->withCsrf([
            'bulk' => "2026-01-01 New Year\nnot a holiday line",
        ]))->assertStatus(400);

        $this->assertCount(0, model(HolidayModel::class)->forYear(2026));
    }

    public function testADateWithoutANameIsRejected(): void
    {
        $this->loginAsManager();

        $this->withSession()->post('/admin/attendance/holidays/create', $this->withCsrf([
            'bulk' => '2026-01-01',
        ]))->assertStatus(400);
    }

    public function testAnEmptyPasteIsRejected(): void
    {
        $this->loginAsManager();

        $this->withSession()->post('/admin/attendance/holidays/create', $this->withCsrf(['bulk' => "  \n  "]))->assertStatus(400);
    }

    public function testTooManyLinesAreRejected(): void
    {
        $this->loginAsManager();

        $lines = [];
        for ($day = 1; $day <= 101; $day++) {
            $lines[] = sprintf('2026-%02d-%02d Day %d', 1 + intdiv($day, 28), 1 + ($day % 28), $day);
        }

        $this->withSession()->post('/admin/attendance/holidays/create', $this->withCsrf([
            'bulk' => implode("\n", $lines),
        ]))->assertStatus(400);

        $this->assertCount(0, model(HolidayModel::class)->forYear(2026));
    }

    public function testDeleteRemovesTheHolidayAndReports404ForAnUnknownId(): void
    {
        $this->loginAsManager();
        $id = (int) model(HolidayModel::class)->insert(['holiday_date' => '2026-09-02', 'name' => 'National Day'], true);

        $this->withSession()->post('/admin/attendance/holidays/delete', $this->withCsrf(['id' => $id]))->assertStatus(200);
        $this->assertNull(model(HolidayModel::class)->findHoliday($id));

        $this->withSession()->post('/admin/attendance/holidays/delete', $this->withCsrf(['id' => 999999]))->assertStatus(404);
    }

    public function testYearFilterOnlyShowsThatYear(): void
    {
        $this->loginAsManager();
        model(HolidayModel::class)->insert(['holiday_date' => '2026-09-02', 'name' => 'National Day']);
        model(HolidayModel::class)->insert(['holiday_date' => '2027-01-01', 'name' => 'New Year 2027']);

        $result = $this->withSession()->get('/admin/attendance/holidays?year=2026');

        $result->assertSee('National Day');
        $result->assertDontSee('New Year 2027');
    }

    public function testCreateRequiresCsrf(): void
    {
        $this->loginAsManager();

        $result = $this->withSession()->post('/admin/attendance/holidays/create', ['bulk' => '2026-09-02 National Day']);

        $this->assertNotSame(200, $result->response()->getStatusCode());
        $this->assertCount(0, model(HolidayModel::class)->forYear(2026));
    }
}
