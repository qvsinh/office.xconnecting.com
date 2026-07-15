<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * `app/Helpers/ip_helper.php` — parsing/validation/matching for the
 * per-API-key IP allowlist enforced by `ApiFilter`. Pure functions, no
 * HTTP/DB dependency.
 *
 * @internal
 */
final class IpHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('ip');
    }

    public function testParseIpRulesSplitsTrimsDedupesAndDropsBlanks(): void
    {
        $rules = parse_ip_rules("192.168.1.1\n, 10.0.0.0/8 ,\n\n192.168.1.1,  \n2001:db8::1");

        $this->assertSame(['192.168.1.1', '10.0.0.0/8', '2001:db8::1'], $rules);
    }

    public function testParseIpRulesReturnsEmptyListForNullOrBlank(): void
    {
        $this->assertSame([], parse_ip_rules(null));
        $this->assertSame([], parse_ip_rules('   '));
        $this->assertSame([], parse_ip_rules(''));
    }

    public function testIpRuleIsValidAcceptsIpsAndCidrs(): void
    {
        $this->assertTrue(ip_rule_is_valid('192.168.1.1'));
        $this->assertTrue(ip_rule_is_valid('::1'));
        $this->assertTrue(ip_rule_is_valid('192.168.1.0/24'));
        $this->assertTrue(ip_rule_is_valid('192.168.1.0/0'));
        $this->assertTrue(ip_rule_is_valid('192.168.1.0/32'));
        $this->assertTrue(ip_rule_is_valid('2001:db8::/32'));
        $this->assertTrue(ip_rule_is_valid('2001:db8::/128'));
    }

    public function testIpRuleIsValidRejectsGarbageAndOutOfRangePrefixes(): void
    {
        $this->assertFalse(ip_rule_is_valid('not-an-ip'));
        $this->assertFalse(ip_rule_is_valid(''));
        $this->assertFalse(ip_rule_is_valid('192.168.1.0/33')); // > 32 for v4
        $this->assertFalse(ip_rule_is_valid('192.168.1.0/-1'));
        $this->assertFalse(ip_rule_is_valid('2001:db8::/129')); // > 128 for v6
        $this->assertFalse(ip_rule_is_valid('192.168.1.0/abc'));
    }

    public function testIpMatchesAllowlistExactMatchNormalizesIpv4AndIpv6Forms(): void
    {
        $this->assertTrue(ip_matches_allowlist('192.168.1.5', ['192.168.1.5']));
        $this->assertFalse(ip_matches_allowlist('192.168.1.6', ['192.168.1.5']));

        // ::1 and its fully-expanded form must compare equal.
        $this->assertTrue(ip_matches_allowlist('::1', ['0:0:0:0:0:0:0:1']));
        $this->assertTrue(ip_matches_allowlist('0:0:0:0:0:0:0:1', ['::1']));
    }

    public function testIpMatchesAllowlistIpv4Cidr(): void
    {
        $rules = ['192.168.1.0/24'];

        $this->assertTrue(ip_matches_allowlist('192.168.1.5', $rules));
        $this->assertTrue(ip_matches_allowlist('192.168.1.254', $rules));
        $this->assertFalse(ip_matches_allowlist('192.168.2.5', $rules));
    }

    public function testIpMatchesAllowlistIpv6Cidr(): void
    {
        $rules = ['2001:db8::/32'];

        $this->assertTrue(ip_matches_allowlist('2001:db8::1', $rules));
        $this->assertTrue(ip_matches_allowlist('2001:db8:ffff::1', $rules));
        $this->assertFalse(ip_matches_allowlist('2001:db9::1', $rules));
    }

    public function testIpMatchesAllowlistBoundaryPrefixes(): void
    {
        // /0 matches every address of that family.
        $this->assertTrue(ip_matches_allowlist('8.8.8.8', ['0.0.0.0/0']));
        $this->assertTrue(ip_matches_allowlist('2001:db8::1', ['::/0']));

        // /32 (v4) and /128 (v6) are exact-address CIDRs.
        $this->assertTrue(ip_matches_allowlist('192.168.1.5', ['192.168.1.5/32']));
        $this->assertFalse(ip_matches_allowlist('192.168.1.6', ['192.168.1.5/32']));
        $this->assertTrue(ip_matches_allowlist('::1', ['::1/128']));
    }

    public function testIpMatchesAllowlistFailsClosedOnEmptyOrInvalidClientIp(): void
    {
        $this->assertFalse(ip_matches_allowlist('', ['0.0.0.0/0']));
        $this->assertFalse(ip_matches_allowlist('not-an-ip', ['0.0.0.0/0']));
    }

    public function testIpMatchesAllowlistIgnoresRulesOfMismatchedFamily(): void
    {
        // An IPv6 client against a v4-only CIDR rule must not match.
        $this->assertFalse(ip_matches_allowlist('2001:db8::1', ['192.168.1.0/24']));
        $this->assertFalse(ip_matches_allowlist('192.168.1.5', ['2001:db8::/32']));
    }
}
