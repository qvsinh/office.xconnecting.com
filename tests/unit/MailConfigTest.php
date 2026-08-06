<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Mail;

/**
 * `Config\Mail` and the `mail.` env group.
 *
 * SparkPost is deliberately absent: no library, no caller, so its three keys could
 * never do anything and were removed along with `mail.provider` (one transport
 * makes a selector pointless).
 *
 * The mapping is the whole point of the class, and it is easy to get silently
 * wrong: CI4 matches env keys to property names character for character, and a
 * mistyped key does not error — the property just keeps its default. That already
 * bit this config once, when a rewrite left doubled quotes in `.env` and the value
 * parsed as an empty string while the `mail.aws.*` keys kept working, so
 * `testTheProjectEnvIsWiredToTheGroup()` reads the real file rather than an
 * injected value.
 *
 * @internal
 */
final class MailConfigTest extends CIUnitTestCase
{
    /**
     * @param array<string, string> $env
     */
    private function withEnv(array $env, callable $assert): void
    {
        $restore = [];

        foreach ($env as $key => $value) {
            $restore[$key] = $_SERVER[$key] ?? null;
            putenv("{$key}={$value}");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }

        try {
            $assert(new Mail());
        } finally {
            foreach ($restore as $key => $previous) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);

                if ($previous !== null) {
                    putenv("{$key}={$previous}");
                    $_ENV[$key]    = $previous;
                    $_SERVER[$key] = $previous;
                }
            }
        }
    }

    // ------------------------------------------------------------- mapping

    public function testTheAwsGroupMapsIntoTheArrayProperty(): void
    {
        $this->withEnv([
            'mail.aws.access_key_id'     => 'AKIAPROBE0000000000',
            'mail.aws.secret_access_key' => 'secret-probe-value',
            'mail.aws.region'            => 'ap-northeast-1',
        ], function (Mail $mail): void {
            $this->assertSame('AKIAPROBE0000000000', $mail->aws['access_key_id']);
            $this->assertSame('secret-probe-value', $mail->aws['secret_access_key']);
            $this->assertSame('ap-northeast-1', $mail->aws['region']);
        });
    }

    public function testTheSenderMaps(): void
    {
        $this->withEnv([
            'mail.sender.name'     => 'Probe Sender',
            'mail.sender.email'    => 'probe@example.com',
            'mail.supporter.email' => 'help@example.com',
        ], function (Mail $mail): void {
            $this->assertSame('Probe Sender', $mail->sender['name']);
            $this->assertSame('probe@example.com', $mail->sender['email']);
            $this->assertSame('help@example.com', $mail->supporter['email']);
        });
    }

    /**
     * The real `.env` is the thing that has to work, not just an injected value.
     */
    public function testTheProjectEnvIsWiredToTheGroup(): void
    {
        $mail = config('Mail');

        $this->assertNotSame('', $mail->sender['email'], 'mail.sender.email is not reaching the config — check for stray quotes in .env.');
        $this->assertNotSame('', $mail->supporter['email'], 'mail.supporter.email is not reaching the config.');
    }

    // -------------------------------------------------------------- switches

    /**
     * `.env` files are written by people, who write `no` as often as `false`.
     *
     * @dataProvider switchProvider
     */
    public function testTheSendingSwitchUnderstandsHowPeopleWriteIt(string $value, bool $expected): void
    {
        $this->withEnv(['mail.sending_enable' => $value], function (Mail $mail) use ($expected, $value): void {
            $this->assertSame($expected, $mail->enabled(), "mail.sending_enable = {$value}");
        });
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function switchProvider(): array
    {
        return [
            'true'  => ['true', true],
            'yes'   => ['yes', true],
            'on'    => ['on', true],
            '1'     => ['1', true],
            'false' => ['false', false],
            'no'    => ['no', false],
            'off'   => ['off', false],
            '0'     => ['0', false],
            'empty' => ['', false],
        ];
    }

    // ------------------------------------------------------------ configured

    /**
     * `configured()` separates "switched off" from "switched on but incomplete" —
     * two very different problems to debug.
     */
    public function testSesNeedsAllThreeValuesToCountAsConfigured(): void
    {
        $complete = [
            'mail.aws.access_key_id'     => 'AKIAPROBE0000000000',
            'mail.aws.secret_access_key' => 'secret',
            'mail.aws.region'            => 'ap-northeast-1',
        ];

        $this->withEnv($complete, fn (Mail $mail) => $this->assertTrue($mail->configured()));

        // A missing region is the easy one to overlook: the credentials look fine
        // and mail simply never arrives.
        $this->withEnv(['mail.aws.region' => ''] + $complete, fn (Mail $mail) => $this->assertFalse(
            $mail->configured(),
            'SES without a region must not count as configured.',
        ));
    }

    /**
     * A coherence check on the real `.env`, not a pinned opinion about it.
     *
     * The previous version asserted "sending is off", which was true when written
     * and became wrong the moment somebody switched SES on — a test asserting an
     * operator's choice. What is worth failing over is the *incoherent* state:
     * sending switched on with incomplete credentials means every notification is
     * quietly recorded as failed and no mail arrives, which is far easier to ship
     * than to notice.
     */
    public function testEnabledSendingMustHaveCompleteCredentials(): void
    {
        $mail = config('Mail');

        if (! $mail->enabled()) {
            $this->assertFalse($mail->enabled());

            return;
        }

        $missing = [];

        foreach (['access_key_id', 'secret_access_key', 'region'] as $key) {
            if (trim((string) ($mail->aws[$key] ?? '')) === '') {
                $missing[] = 'mail.aws.' . $key;
            }
        }

        $this->assertSame(
            [],
            $missing,
            "mail.sending_enable is on, so SES has to be complete. Missing: " . implode(', ', $missing)
            . '. Until these are set, every notification is logged as failed and nothing is delivered.',
        );
    }
}
