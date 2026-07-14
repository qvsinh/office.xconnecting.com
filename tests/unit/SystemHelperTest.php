<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * `log_dir_files()`/`tail_lines()` — the shared allowlist + bounded-tail
 * helpers behind `Admin\SystemLogs`. Fixture files are created under the
 * real `writable/logs/` (the only directory these functions ever read) and
 * removed in tearDown so the suite never leaves stray files behind.
 *
 * @internal
 */
final class SystemHelperTest extends CIUnitTestCase
{
    private const FIXTURE_LOG   = 'system-helper-test-fixture.log';
    private const FIXTURE_OTHER = 'system-helper-test-fixture.txt';

    private string $logsDir;

    protected function setUp(): void
    {
        parent::setUp();
        helper('system');
        $this->logsDir = WRITEPATH . 'logs/';
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        @unlink($this->logsDir . self::FIXTURE_LOG);
        @unlink($this->logsDir . self::FIXTURE_OTHER);
    }

    public function testLogDirFilesExcludesNonLogFilesAndDirectories(): void
    {
        file_put_contents($this->logsDir . self::FIXTURE_LOG, "line one\n");
        file_put_contents($this->logsDir . self::FIXTURE_OTHER, 'not a log');

        $names = array_column(log_dir_files(), 'name');

        $this->assertContains(self::FIXTURE_LOG, $names);
        $this->assertNotContains(self::FIXTURE_OTHER, $names);
        $this->assertNotContains('.', $names);
        $this->assertNotContains('..', $names);
    }

    public function testLogDirFilesReturnsNameSizeAndMtime(): void
    {
        file_put_contents($this->logsDir . self::FIXTURE_LOG, '12345');

        $found = null;
        foreach (log_dir_files() as $file) {
            if ($file['name'] === self::FIXTURE_LOG) {
                $found = $file;
            }
        }

        $this->assertNotNull($found);
        $this->assertSame(5, $found['size']);
        $this->assertIsInt($found['mtime']);
    }

    public function testTailLinesReturnsAllLinesWhenUnderMax(): void
    {
        file_put_contents($this->logsDir . self::FIXTURE_LOG, "one\ntwo\nthree\n");

        $this->assertSame("one\ntwo\nthree", tail_lines($this->logsDir . self::FIXTURE_LOG, 2000));
    }

    public function testTailLinesRespectsMaxAndKeepsNewestLines(): void
    {
        $content = implode("\n", array_map(static fn (int $i): string => "line{$i}", range(1, 10))) . "\n";
        file_put_contents($this->logsDir . self::FIXTURE_LOG, $content);

        $tail  = tail_lines($this->logsDir . self::FIXTURE_LOG, 3);
        $lines = explode("\n", $tail);

        $this->assertCount(3, $lines);
        $this->assertSame(['line8', 'line9', 'line10'], $lines);
    }
}
