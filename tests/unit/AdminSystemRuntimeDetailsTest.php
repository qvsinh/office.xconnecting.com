<?php

use App\Controllers\Admin\System;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * `Admin\System::buildRuntimeDetails()` — the OPcache/PHP-config/server/
 * extensions whitelist behind the System info page's runtime panels. Pure
 * unit test (no DB, no request/response state) mirroring the discipline of
 * `buildInfoData()`'s no-secrets test: every value is hand-picked, so this
 * asserts the shape and, most importantly, that nothing wider ever leaked
 * in (a seeded secret, an absolute deploy path, a `phpinfo()`-style dump).
 * The HTTP-rendered page itself is covered by AdminSystemViewTest /
 * AdminSystemGateTest once `frontend-coder` wires the view.
 *
 * @internal
 */
final class AdminSystemRuntimeDetailsTest extends CIUnitTestCase
{
    public function testReturnsTheFourExpectedTopLevelKeys(): void
    {
        $data = (new System())->buildRuntimeDetails();

        $this->assertArrayHasKey('opcache', $data);
        $this->assertArrayHasKey('php_config', $data);
        $this->assertArrayHasKey('server', $data);
        $this->assertArrayHasKey('extensions', $data);
    }

    public function testOpcacheSectionDegradesGracefullyWhenUnavailable(): void
    {
        $data = (new System())->buildRuntimeDetails();

        if (! function_exists('opcache_get_status') || opcache_get_status(false) === false) {
            $this->assertSame(['available' => false], $data['opcache']);

            return;
        }

        // OPcache IS loaded in this runtime: assert the full safe summary shape instead.
        $this->assertTrue($data['opcache']['available']);
        $this->assertIsBool($data['opcache']['enabled']);
        $this->assertIsInt($data['opcache']['memory_used']);
        $this->assertIsInt($data['opcache']['memory_free']);
        $this->assertIsInt($data['opcache']['memory_wasted']);
        $this->assertIsFloat($data['opcache']['hit_rate']);
        $this->assertIsInt($data['opcache']['num_cached_scripts']);
        $this->assertArrayNotHasKey('scripts', $data['opcache']);
    }

    public function testPhpConfigContainsOnlyTheAllowlistedDirectives(): void
    {
        $data = (new System())->buildRuntimeDetails();

        $expectedKeys = [
            'memory_limit', 'max_execution_time', 'max_input_time', 'upload_max_filesize',
            'post_max_size', 'max_input_vars', 'default_socket_timeout', 'date.timezone',
            'display_errors', 'error_reporting', 'opcache.enable', 'opcache.memory_consumption',
            'session.gc_maxlifetime',
        ];

        $this->assertSame($expectedKeys, array_keys($data['php_config']));

        // Never a path/log directive.
        foreach (['error_log', 'session.save_path', 'open_basedir', 'doc_root', 'extension_dir', 'sys_temp_dir', 'upload_tmp_dir'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data['php_config']);
        }
    }

    public function testServerSectionExcludesHostnameAndFullServerSuperglobal(): void
    {
        $data = (new System())->buildRuntimeDetails();

        $this->assertSame(PHP_VERSION, $data['server']['php_version']);
        $this->assertSame(PHP_SAPI, $data['server']['php_sapi']);
        $this->assertArrayHasKey('os', $data['server']);
        $this->assertArrayHasKey('os_release', $data['server']);
        $this->assertArrayHasKey('architecture', $data['server']);
        $this->assertArrayHasKey('server_time', $data['server']);
        $this->assertArrayHasKey('server_timezone', $data['server']);

        // Never a hostname-leaking key, never a raw $_SERVER dump.
        $this->assertArrayNotHasKey('hostname', $data['server']);
        $this->assertArrayNotHasKey('SERVER_NAME', $data['server']);
        $this->assertArrayNotHasKey('DOCUMENT_ROOT', $data['server']);
    }

    public function testExtensionsListIsSortedAndEachEntryHasNameAndVersion(): void
    {
        $data = (new System())->buildRuntimeDetails();

        $this->assertNotEmpty($data['extensions']);

        $names = array_column($data['extensions'], 'name');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names);

        foreach ($data['extensions'] as $extension) {
            $this->assertArrayHasKey('name', $extension);
            $this->assertArrayHasKey('version', $extension);
            $this->assertIsString($extension['version']);
        }
    }

    public function testNoKnownSecretOrAbsolutePathLeaksIntoRuntimeDetails(): void
    {
        $data    = (new System())->buildRuntimeDetails();
        $encoded = json_encode($data);

        $secrets = [
            getenv('database.tests.password'),
            getenv('database.default.password'),
            getenv('hashid_salt'),
            getenv('encryption.key'),
            getenv('app.basic.auth.password'),
        ];

        foreach ($secrets as $secret) {
            if ($secret === false || $secret === '') {
                continue;
            }

            $this->assertStringNotContainsString((string) $secret, $encoded);
        }

        $this->assertStringNotContainsString(APPPATH, $encoded);
        $this->assertStringNotContainsString(ROOTPATH, $encoded);
        $this->assertStringNotContainsString(WRITEPATH, $encoded);
    }
}
