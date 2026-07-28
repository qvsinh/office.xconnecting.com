<?php

use App\Validation\PasswordRules;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * `strong_password` — the shared password policy.
 *
 * Pure logic, no DB. The generator is covered here too: a generator that emits
 * something its own rule rejects would silently create accounts that violate the
 * policy the forms advertise.
 *
 * @internal
 */
final class PasswordRulesTest extends CIUnitTestCase
{
    private PasswordRules $rule;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rule = new PasswordRules();
    }

    public function testAcceptsAPasswordMeetingEveryRequirement(): void
    {
        $error = null;

        $this->assertTrue($this->rule->strong_password('Str0ng!pass', $error));
        $this->assertNull($error);
    }

    /**
     * @dataProvider tooShortProvider
     */
    public function testRejectsAnythingShorterThanTheMinimum(string $password): void
    {
        $error = null;

        $this->assertFalse($this->rule->strong_password($password, $error));
        $this->assertStringContainsString('at least 8 characters', (string) $error);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function tooShortProvider(): array
    {
        return [
            [''],
            ['a'],
            ['Ab1!'],
            ['Ab1!def'],   // 7 characters — one short
        ];
    }

    public function testRejectsAPasswordLongerThanTheMaximum(): void
    {
        $error = null;

        $this->assertFalse($this->rule->strong_password('Aa1!' . str_repeat('x', 200), $error));
        $this->assertStringContainsString('128', (string) $error);
    }

    /**
     * Each case is long enough but missing exactly one character class, and the
     * message must name the missing one — a user who cannot see what is wrong
     * just retries variations of the same rejected password.
     *
     * @dataProvider missingClassProvider
     */
    public function testRejectsAndNamesTheMissingCharacterClass(string $password, string $expected): void
    {
        $error = null;

        $this->assertFalse($this->rule->strong_password($password, $error));
        $this->assertStringContainsString($expected, (string) $error);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function missingClassProvider(): array
    {
        return [
            'no lowercase' => ['STR0NG!PASS', 'lowercase'],
            'no uppercase' => ['str0ng!pass', 'uppercase'],
            'no number'    => ['Strong!pass', 'number'],
            'no special'   => ['Str0ngpass1', 'special character'],
        ];
    }

    public function testReportsEveryMissingRequirementAtOnce(): void
    {
        $error = null;

        // Long enough, but lowercase letters only.
        $this->assertFalse($this->rule->strong_password('abcdefghij', $error));
        $this->assertStringContainsString('uppercase', (string) $error);
        $this->assertStringContainsString('number', (string) $error);
        $this->assertStringContainsString('special character', (string) $error);
    }

    public function testAUnicodePasswordIsMeasuredInCharactersNotBytes(): void
    {
        $error = null;

        // 8 multi-byte characters would be 24 bytes — strlen() would wrongly
        // accept a 3-character password here.
        $this->assertFalse($this->rule->strong_password('Aa1!áé', $error));
        $this->assertStringContainsString('at least 8 characters', (string) $error);
    }

    public function testANonLatinCharacterCountsAsSpecial(): void
    {
        // Not a letter or digit in the [A-Za-z0-9] sense, so it satisfies the
        // special-character requirement — worth pinning down deliberately.
        $this->assertTrue($this->rule->strong_password('Str0ngpass€'));
    }

    public function testNullIsRejectedRatherThanCrashing(): void
    {
        $this->assertFalse($this->rule->strong_password(null));
    }

    public function testDescribeListsEveryEnforcedRequirement(): void
    {
        $described = PasswordRules::describe();

        $this->assertContains('at least 8 characters', $described);
        // The note the form renders must cover every rule the checker applies.
        $this->assertCount(count(PasswordRules::REQUIREMENTS) + 1, $described);

        foreach (array_keys(PasswordRules::REQUIREMENTS) as $label) {
            $this->assertContains($label, $described);
        }
    }

    public function testTheGeneratorAlwaysSatisfiesItsOwnRule(): void
    {
        for ($i = 0; $i < 500; $i++) {
            $password = PasswordRules::generate();
            $error    = null;

            $this->assertTrue(
                $this->rule->strong_password($password, $error),
                "generate() produced a password its own rule rejects: {$password} ({$error})",
            );
        }
    }

    public function testTheGeneratorHonoursALengthAndNeverGoesBelowTheMinimum(): void
    {
        $this->assertSame(24, mb_strlen(PasswordRules::generate(24)));
        // A caller asking for something too short still gets a valid password.
        $this->assertSame(PasswordRules::MIN_LENGTH, mb_strlen(PasswordRules::generate(3)));
    }

    public function testTheGeneratorDoesNotRepeatItself(): void
    {
        $seen = [];

        for ($i = 0; $i < 100; $i++) {
            $seen[PasswordRules::generate()] = true;
        }

        $this->assertCount(100, $seen);
    }
}
