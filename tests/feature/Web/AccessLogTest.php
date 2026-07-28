<?php

use App\Libraries\AccessLogger;
use App\Models\AccessLogModel;
use App\Models\RoleModel;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Web\WebAuthFixture;

/**
 * Access logging: login / check-in / check-out are recorded with their device
 * context, and only administrators can read it.
 *
 * The properties worth pinning down:
 *  - the three events are actually captured (a log nobody writes to is worse
 *    than no log, because it looks like nothing happened);
 *  - a failed login writes nothing — otherwise the table fills with noise and
 *    "this account logged in" stops meaning anything;
 *  - the pages are admin-zone only, *not* `attendance.manage`: a department head
 *    correcting hours has no business reading everyone's addresses;
 *  - writing a log can never break the action it is logging.
 *
 * @internal
 */
final class AccessLogTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use WebAuthFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    /**
     * A recognisable desktop Chrome string, so the parsed columns have something
     * real to chew on rather than the test client's empty agent.
     */
    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    protected function tearDown(): void
    {
        service('superglobals')->setServer('HTTP_USER_AGENT', '');
        Services::resetSingle('request');
        parent::tearDown();
        session()->destroy();
    }

    /**
     * CI4's `UserAgent` reads `$_SERVER['HTTP_USER_AGENT']` directly (see its
     * constructor) rather than the request's header bag, so `withHeaders()`
     * cannot reach it. In a real request the superglobal *is* the header, so
     * this sets it the way the framework actually reads it.
     */
    private function withUserAgent(string $agent): void
    {
        service('superglobals')->setServer('HTTP_USER_AGENT', $agent);
        Services::resetSingle('request');
    }

    private function logs(?int $userId = null): array
    {
        $model = model(AccessLogModel::class);

        if ($userId !== null) {
            $model->where('user_id', $userId);
        }

        return $model->orderBy('id', 'ASC')->findAll();
    }

    // ------------------------------------------------------------- capturing

    public function testLoginIsRecordedWithDeviceContext(): void
    {
        $user = $this->seedWebUser(['email' => 'logged@example.com']);

        $this->withUserAgent(self::CHROME);

        $this->withSession(['basicAuthAuthenticated' => 1])
            ->post('/login', $this->withCsrf([
                'email'         => $user->email,
                'password'      => self::WEB_AUTH_TEST_PASSWORD,
                'client_screen' => '1920x1080',
            ]));

        $logs = $this->logs((int) $user->id);
        $this->assertCount(1, $logs);

        $log = $logs[0];
        $this->assertSame(AccessLogModel::EVENT_LOGIN, $log->event);
        $this->assertStringContainsString('Chrome', (string) $log->browser);
        $this->assertSame('desktop', $log->device_type);
        $this->assertSame(1920, (int) $log->screen_width);
        $this->assertSame(1080, (int) $log->screen_height);
        // The raw header is kept so a better parser can be run over it later.
        $this->assertSame(self::CHROME, $log->user_agent);
    }

    public function testAFailedLoginRecordsNothing(): void
    {
        $user = $this->seedWebUser(['email' => 'wrongpass@example.com']);

        $this->withSession(['basicAuthAuthenticated' => 1])->post('/login', $this->withCsrf([
            'email'    => $user->email,
            'password' => 'not-the-password',
        ]));

        $this->assertSame([], $this->logs());
    }

    public function testCheckInAndCheckOutAreRecordedSeparately(): void
    {
        $user = $this->seedWebUser(['email' => 'attendance@example.com']);
        $this->loginAs($user);

        $this->withUserAgent(self::IPHONE);
        $this->withSession()->post('/attendance/checkin', $this->withCsrf(['client_screen' => '390x844']));
        $this->withSession()->post('/attendance/checkout', $this->withCsrf(['client_screen' => '390x844']));

        $events = array_map(static fn (object $row): string => $row->event, $this->logs((int) $user->id));

        // login (from loginAs) + checkin + checkout, in that order.
        $this->assertSame(
            [AccessLogModel::EVENT_LOGIN, AccessLogModel::EVENT_CHECKIN, AccessLogModel::EVENT_CHECKOUT],
            $events,
        );

        $checkin = $this->logs((int) $user->id)[1];
        $this->assertSame('mobile', $checkin->device_type);
        $this->assertSame(390, (int) $checkin->screen_width);
    }

    public function testARejectedCheckInRecordsNothing(): void
    {
        $user = $this->seedWebUser(['email' => 'double@example.com']);
        $this->loginAs($user);

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]));
        // Second attempt is refused (409) — it must not leave a log line claiming
        // a check-in happened.
        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(409);

        $events = array_map(static fn (object $row): string => $row->event, $this->logs((int) $user->id));
        $this->assertSame([AccessLogModel::EVENT_LOGIN, AccessLogModel::EVENT_CHECKIN], $events);
    }

    // -------------------------------------------------- client-supplied input

    /**
     * @dataProvider badScreenProvider
     */
    public function testAMalformedScreenIsStoredAsNullRatherThanNonsense(string $screen): void
    {
        $user = $this->seedWebUser(['email' => 'badscreen-' . md5($screen) . '@example.com']);
        $this->loginAs($user);

        $this->withSession()->post('/attendance/checkin', $this->withCsrf(['client_screen' => $screen]));

        $checkin = $this->logs((int) $user->id)[1];
        $this->assertNull($checkin->screen_width);
        $this->assertNull($checkin->screen_height);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function badScreenProvider(): array
    {
        return [
            'empty'         => [''],
            'not a size'    => ['<script>alert(1)</script>'],
            'wrong shape'   => ['1920*1080'],
            'absurdly big'  => ['99999x99999'],
            'absurdly tiny' => ['1x1'],
            'negative'      => ['-800x600'],
        ];
    }

    // --------------------------------------------------------------- access

    public function testAPlainEmployeeCannotReadTheAccessLog(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'nosy@example.com', 'zone' => 'user']));

        $this->withSession()->get('/admin/access-logs')->assertRedirectTo('/dashboard');
        $this->withSession()->get('/admin/access-logs/user/1')->assertRedirectTo('/dashboard');
        $this->withSession()->get('/admin/access-logs/show/1')->assertRedirectTo('/dashboard');
    }

    /**
     * The attendance permission deliberately does NOT open this door: correcting
     * a team's hours is a different thing from reading everyone's IP addresses.
     */
    public function testAnAttendanceManagerWithoutAdminZoneCannotReadTheAccessLog(): void
    {
        $roleId     = (int) model(RoleModel::class)->insert(['name' => 'attendance-manager', 'label' => 'Manager'], true);
        $permission = $this->db->table('permissions')->where('name', 'attendance.manage')->get()->getRow();
        $this->db->table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => (int) $permission->id]);

        $manager = $this->seedWebUser(['email' => 'head@example.com', 'zone' => 'user', 'role_id' => $roleId]);
        $this->loginAs($manager);

        // They can manage attendance...
        $this->withSession()->get('/admin/attendance')->assertStatus(200);
        // ...but not read the access log.
        $this->withSession()->get('/admin/access-logs')->assertRedirectTo('/dashboard');
    }

    public function testGuestIsRedirectedToLogin(): void
    {
        $this->get('/admin/access-logs')->assertRedirectTo('/login');
    }

    public function testAnAdminSeesTheLogAndItsDetail(): void
    {
        $employee = $this->seedWebUser(['email' => 'watched@example.com', 'name' => 'Watched Person']);
        $admin    = $this->seedWebUser(['email' => 'boss@example.com', 'zone' => 'admin']);

        (new AccessLogger())->record(AccessLogModel::EVENT_CHECKIN, (int) $employee->id);
        $this->loginAs($admin);

        $list = $this->withSession()->get('/admin/access-logs');
        $list->assertStatus(200);
        $list->assertSee('Watched Person');

        $logId  = (int) $this->logs((int) $employee->id)[0]->id;
        $detail = $this->withSession()->get('/admin/access-logs/show/' . $logId);
        $detail->assertStatus(200);
        $this->assertSame(AccessLogModel::EVENT_CHECKIN, json_decode($detail->getJSON(), true)['entry']['event']);

        $perUser = $this->withSession()->get('/admin/access-logs/user/' . $employee->id);
        $perUser->assertStatus(200);
        $perUser->assertSee('Devices seen');
    }

    public function testTheEventFilterNarrowsTheList(): void
    {
        $employee = $this->seedWebUser(['email' => 'filtered@example.com', 'name' => 'Filtered Person']);
        $logger   = new AccessLogger();
        $logger->record(AccessLogModel::EVENT_CHECKIN, (int) $employee->id);
        $logger->record(AccessLogModel::EVENT_CHECKOUT, (int) $employee->id);

        $model = model(AccessLogModel::class);
        $this->assertSame(2, $model->countAdmin([]));
        $this->assertSame(1, $model->countAdmin(['event' => AccessLogModel::EVENT_CHECKIN]));
        $this->assertSame(0, $model->countAdmin(['event' => AccessLogModel::EVENT_LOGIN]));
        // An unknown event value must not silently return everything.
        $this->assertSame(2, $model->countAdmin(['event' => 'nonsense']));
    }

    public function testAnUnknownDetailIdIs404(): void
    {
        $this->loginAs($this->seedWebUser(['email' => 'boss2@example.com', 'zone' => 'admin']));

        $this->withSession()->get('/admin/access-logs/show/999999')->assertStatus(404);
    }

    // ------------------------------------------------------------ robustness

    public function testLoggingNeverBreaksTheActionItLogs(): void
    {
        // The table is gone; a check-in must still succeed. An audit write that
        // can refuse somebody entry to the building is worse than a missing line.
        $this->db->query('DROP TABLE access_logs');

        $user = $this->seedWebUser(['email' => 'resilient@example.com']);
        $this->loginAs($user);

        $this->withSession()->post('/attendance/checkin', $this->withCsrf([]))->assertStatus(200);
    }

    public function testAnUnknownEventOrUserIsNotRecorded(): void
    {
        $logger = new AccessLogger();
        $logger->record('exfiltrate', 1);
        $logger->record(AccessLogModel::EVENT_LOGIN, 0);

        $this->assertSame([], $this->logs());
    }
}
