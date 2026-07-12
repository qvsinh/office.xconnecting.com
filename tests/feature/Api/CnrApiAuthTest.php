<?php

use App\Models\ApiAccessKeyModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Api\ApiKeyFixture;

/**
 * Auth accept/reject paths shared by both proxy endpoints, exercised via
 * the CNR route. `ApiController::checkUserAccess()` behaves identically
 * regardless of which concrete proxy controller runs.
 *
 * BLOCKED (see testRejectPathsCannotBeFeatureTested... below for the full
 * repro): `ApiController::rejectUnauthorized()` calls `$this->response->
 * ...->send()->exit()` directly. `exit()` is a real PHP language construct —
 * under FeatureTestTrait the app runs in-process inside the PHPUnit worker,
 * so hitting this path terminates the entire PHPUnit process (confirmed:
 * the raw JSON body is echoed to stdout by send() and the run stops with
 * exit code 0, no test result is ever reported). Every "auth reject" /
 * "env mismatch" scenario in the plan's Integration test list goes through
 * this exact path and is therefore unrunnable via FeatureTestTrait until
 * fixed. Reported to backend-coder — see the tester's final report for the
 * recommended fix (return a Response instead of send()+exit(), e.g. via a
 * dedicated exception caught by the global handler, or short-circuiting in
 * ApiFilter::before() which CI4 supports natively).
 *
 * @internal
 */
final class CnrApiAuthTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use ApiKeyFixture;

    protected $migrate   = true;
    protected $refresh   = true;
    protected $namespace = null;

    /**
     * Documents every reject-path scenario the plan requires (missing
     * bearer, empty token, malformed token, disabled key, user_id mismatch,
     * service_id mismatch, env mismatch on /live and /testing) without
     * running them — each one crashes the PHPUnit process (see class
     * docblock). Un-skip and split into per-scenario tests once
     * ApiController::rejectUnauthorized() no longer calls exit() directly.
     */
    public function testRejectPathsCrashPhpunitViaControllerExit(): void
    {
        $this->markTestSkipped(
            'Blocked: ApiController::rejectUnauthorized() calls send()->exit() directly, '
            . 'which kills the whole PHPUnit process instead of failing one test '
            . '(reproduced: raw JSON is echoed to stdout, run stops at exit code 0, '
            . 'no test result reported). Fix in ApiController before un-skipping.'
        );
    }

    public function testValidEnabledKeyMatchingEnvIsAccepted(): void
    {
        $key = $this->seedApiKey(1, 0, 800);

        // No command param -> reaches the controller (auth passes) and
        // returns the 400 "missing command" envelope instead of 401.
        $result = $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $result->assertStatus(400);
        $result->assertJSONFragment(['status' => 'error']);
    }

    public function testLastAccessUpdatedOnAuthenticatedCall(): void
    {
        $key = $this->seedApiKey(1, 0, 800);

        $before = model(ApiAccessKeyModel::class)->find($key['id']);
        $this->assertNull($before->last_access);

        $this->withHeaders(['Authorization' => 'Bearer ' . $key['token']])
            ->get('/api/cnr/testing');

        $after = model(ApiAccessKeyModel::class)->find($key['id']);
        $this->assertNotNull($after->last_access);
    }
}
