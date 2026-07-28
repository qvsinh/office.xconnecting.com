<?php

use App\Libraries\PasswordHasher;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Auth;

/**
 * Password storage: bcrypt/argon2 through `password_hash()`, with the legacy
 * unsalted-MD5 rows still verifying so nobody is locked out mid-migration.
 *
 * @internal
 */
final class PasswordHasherTest extends CIUnitTestCase
{
    /**
     * Cost 4 (bcrypt's minimum) everywhere here — these tests are about the
     * logic, not about how long a digest takes.
     */
    private function config(string $algorithm = 'bcrypt', int $cost = 4, string $legacy = 'md5', string $salt = ''): Auth
    {
        $config                     = new Auth();
        $config->password_algorithm = $algorithm;
        $config->password_cost      = $cost;
        $config->legacy_algorithm   = $legacy;
        $config->legacy_salt        = $salt;

        return $config;
    }

    private function hasher(string $algorithm = 'bcrypt', int $cost = 4): PasswordHasher
    {
        return new PasswordHasher($this->config($algorithm, $cost));
    }

    // ------------------------------------------------------------- hashing

    public function testAHashIsSaltedSoTheSamePasswordHashesDifferentlyEveryTime(): void
    {
        $hasher = $this->hasher();

        $first  = $hasher->hash('Str0ng!Pass');
        $second = $hasher->hash('Str0ng!Pass');

        // This is the whole point of the change: unsalted MD5 gave one digest per
        // password, so one rainbow table cracked every account at once.
        $this->assertNotSame($first, $second);
        $this->assertTrue($hasher->verify('Str0ng!Pass', $first));
        $this->assertTrue($hasher->verify('Str0ng!Pass', $second));
    }

    public function testTheStoredHashIsNotTheLegacyDigest(): void
    {
        $stored = $this->hasher()->hash('Str0ng!Pass');

        $this->assertStringStartsWith('$2y$', $stored);
        $this->assertNotSame(md5('Str0ng!Pass'), $stored);
        $this->assertSame('bcrypt', password_get_info($stored)['algoName']);
    }

    public function testTheConfiguredCostIsApplied(): void
    {
        $stored = $this->hasher('bcrypt', 6)->hash('Str0ng!Pass');

        $this->assertSame(6, password_get_info($stored)['options']['cost']);
    }

    /**
     * @dataProvider algorithmProvider
     */
    public function testEverySupportedAlgorithmRoundTrips(string $algorithm): void
    {
        if ($algorithm !== 'bcrypt' && $algorithm !== 'default' && ! in_array($algorithm, password_algos(), true)) {
            $this->markTestSkipped($algorithm . ' is not compiled into this PHP.');
        }

        $hasher = $this->hasher($algorithm);
        $stored = $hasher->hash('Str0ng!Pass');

        $this->assertTrue($hasher->verify('Str0ng!Pass', $stored));
        $this->assertFalse($hasher->verify('wrong', $stored));
        $this->assertFalse($hasher->needsRehash($stored));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function algorithmProvider(): array
    {
        return [
            'bcrypt'   => ['bcrypt'],
            'default'  => ['default'],
            'argon2i'  => ['argon2i'],
            'argon2id' => ['argon2id'],
        ];
    }

    // ----------------------------------------------------------- verifying

    public function testAWrongPasswordIsRejected(): void
    {
        $hasher = $this->hasher();
        $stored = $hasher->hash('Str0ng!Pass');

        $this->assertFalse($hasher->verify('Str0ng!Pas', $stored));
        $this->assertFalse($hasher->verify('str0ng!pass', $stored));
        $this->assertFalse($hasher->verify('', $stored));
    }

    /**
     * A row with no hash must never authenticate — otherwise an account created
     * without a password would accept anything.
     *
     * @dataProvider emptyStoredProvider
     */
    public function testAnEmptyStoredHashNeverVerifies(?string $stored): void
    {
        $this->assertFalse($this->hasher()->verify('anything', $stored));
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function emptyStoredProvider(): array
    {
        return ['empty string' => [''], 'null' => [null]];
    }

    // -------------------------------------------------------------- legacy

    public function testALegacyUnsaltedMd5StillVerifies(): void
    {
        $hasher = $this->hasher();

        // Exactly what the old code wrote: hash('md5', $plain . '').
        $this->assertTrue($hasher->verify('Str0ng!Pass', md5('Str0ng!Pass')));
        $this->assertFalse($hasher->verify('wrong', md5('Str0ng!Pass')));
    }

    public function testALegacyHashWithASaltVerifiesWhenTheSaltIsConfigured(): void
    {
        $hasher = new PasswordHasher($this->config(legacy: 'md5', salt: 'pepper'));

        $this->assertTrue($hasher->verify('Str0ng!Pass', md5('Str0ng!Passpepper')));
        $this->assertFalse($hasher->verify('Str0ng!Pass', md5('Str0ng!Pass')));
    }

    /**
     * The escape hatch for after the migration: with no legacy algorithm
     * configured, an old digest stops being accepted at all.
     */
    public function testLegacyHashesAreRefusedWhenTheLegacyAlgorithmIsDisabled(): void
    {
        $hasher = new PasswordHasher($this->config(legacy: ''));

        $this->assertFalse($hasher->verify('Str0ng!Pass', md5('Str0ng!Pass')));
        // Modern hashes still work, so disabling it is safe once rows are migrated.
        $this->assertTrue($hasher->verify('Str0ng!Pass', $hasher->hash('Str0ng!Pass')));
    }

    // ----------------------------------------------------------- rehashing

    public function testALegacyHashNeedsRehashingAndAFreshOneDoesNot(): void
    {
        $hasher = $this->hasher();

        $this->assertTrue($hasher->needsRehash(md5('Str0ng!Pass')));
        $this->assertFalse($hasher->needsRehash($hasher->hash('Str0ng!Pass')));
    }

    public function testRaisingTheCostMarksExistingHashesForRehashing(): void
    {
        $cheap = $this->hasher('bcrypt', 4)->hash('Str0ng!Pass');

        $this->assertTrue($this->hasher('bcrypt', 6)->needsRehash($cheap));
        // ...and the old hash still verifies in the meantime.
        $this->assertTrue($this->hasher('bcrypt', 6)->verify('Str0ng!Pass', $cheap));
    }

    /**
     * @dataProvider emptyStoredProvider
     */
    public function testAMissingHashCountsAsNeedingRehash(?string $stored): void
    {
        $this->assertTrue($this->hasher()->needsRehash($stored));
    }

    // ------------------------------------------------------ configuration

    public function testAnUnknownAlgorithmFailsAtConstructionNotAtLoginTime(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Unknown auth\.password_algorithm/');

        new PasswordHasher($this->config('rot13'));
    }

    /**
     * A nonsense cost must not make every hash attempt throw — the value is
     * clamped into bcrypt's 4..31 range instead.
     *
     * @dataProvider absurdCostProvider
     */
    public function testAnAbsurdCostIsClampedRatherThanFatal(int $cost, int $expected): void
    {
        $stored = $this->hasher('bcrypt', $cost)->hash('Str0ng!Pass');

        $this->assertSame($expected, password_get_info($stored)['options']['cost']);
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function absurdCostProvider(): array
    {
        // The upper bound is only checked for clamping, not exercised — cost 31
        // would take minutes to compute.
        return ['zero' => [0, 4], 'negative' => [-5, 4]];
    }
}
