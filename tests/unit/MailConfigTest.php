<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Mail;

/**
 * `Config\Mail` and the `mail.` env group.
 *
 * The mapping is the whole point of the class, and it is easy to get silently
 * wrong: CI4 matches env keys to property names character for character, and a
 * mistyped key does not error — the property just keeps its default. That already
 * bit this config once, when a rewrite left `mail.provider = ''aws''` in `.env`
 * and the doubled quotes parsed as an empty string, so `provider` silently fell
 * back while `mail.aws.*` kept working.
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

    public function testTheSparkpostGroupMapsIntoTheArrayProperty(): void
    {
        $this->withEnv([
            'mail.sparkpost.key'    => 'sp-probe-key',
            'mail.sparkpost.isSMTP' => '1',
        ], function (Mail $mail): void {
            $this->assertSame('sp-probe-key', $mail->sparkpost['key']);
            $this->assertSame('1', $mail->sparkpost['isSMTP']);
        });
    }

    public function testTheSenderAndProviderMap(): void
    {
        $this->withEnv([
            'mail.provider'     => 'sparkpost',
            'mail.sender.name'  => 'Probe Sender',
            'mail.sender.email' => 'probe@example.com',
        ], function (Mail $mail): void {
            $this->assertSame('sparkpost', $mail->provider);
            $this->assertSame('Probe Sender', $mail->sender['name']);
            $this->assertSame('probe@example.com', $mail->sender['email']);
        });
    }

    /**
     * The real `.env` is the thing that has to work, not just an injected value.
     */
    public function testTheProjectEnvIsWiredToTheGroup(): void
    {
        $mail = config('Mail');

        $this->assertNotSame('', $mail->provider, 'mail.provider is not reaching the config — check for stray quotes in .env.');
        $this->assertContains($mail->provider, ['aws', 'sparkpost']);
        $this->assertNotSame('', $mail->sender['email'], 'mail.sender.email is not reaching the config.');
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
            'mail.provider'              => 'aws',
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

    public function testSparkpostOnlyNeedsItsKey(): void
    {
        $this->withEnv([
            'mail.provider'      => 'sparkpost',
            'mail.sparkpost.key' => 'sp-probe-key',
        ], fn (Mail $mail) => $this->assertTrue($mail->configured()));

        $this->withEnv([
            'mail.provider'      => 'sparkpost',
            'mail.sparkpost.key' => '',
        ], fn (Mail $mail) => $this->assertFalse($mail->configured()));
    }

    /**
     * As shipped: credentials are present but nothing is switched on, and no
     * region is set. Pinned so the state is explicit rather than surprising.
     */
    public function testMailIsNotEnabledInThisProject(): void
    {
        $mail = config('Mail');

        $this->assertFalse($mail->enabled(), 'Nothing dispatches mail yet; the switch should stay off.');
        $this->assertFalse($mail->configured(), 'mail.aws.region is unset, so SES is not usable yet.');
    }
}
