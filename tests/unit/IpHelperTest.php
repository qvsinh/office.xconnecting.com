<?php

use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * `app/Helpers/ip_helper.php` — parsing/validation/matching for the
 * per-API-key IP allowlist enforced by `ApiFilter`, plus `resolve_client_ip()`
 * (direct vs behind-Cloudflare real-IP resolution). Pure functions / a
 * manually-built request, no DB dependency.
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

    /**
     * Builds an IncomingRequest with a pinned REMOTE_ADDR and an optional
     * CF-Connecting-IP header, mirroring the superglobals-pinning approach
     * used by `ApiFilterIpAllowlistTest` — CI4's request harness doesn't
     * populate `$_SERVER['REMOTE_ADDR']` on its own under a CLI test run.
     */
    private function makeRequest(string $remoteAddr, ?string $cfConnectingIp = null): IncomingRequest
    {
        service('superglobals')->setServer('REMOTE_ADDR', $remoteAddr);

        $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());

        if ($cfConnectingIp !== null) {
            $request->setHeader('CF-Connecting-IP', $cfConnectingIp);
        }

        return $request;
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

    public function testCloudflareIpRangesIsNonEmptyAndAllValid(): void
    {
        $ranges = cloudflare_ip_ranges();

        $this->assertNotEmpty($ranges);

        foreach ($ranges as $range) {
            $this->assertTrue(ip_rule_is_valid($range), "Invalid CIDR: {$range}");
        }
    }

    public function testResolveClientIpDirectTopologyReturnsRemoteAddr(): void
    {
        $request = $this->makeRequest('203.0.113.9');

        $this->assertSame('203.0.113.9', resolve_client_ip($request));
    }

    public function testResolveClientIpTrustsCfConnectingIpFromGenuineCloudflareEdge(): void
    {
        // 173.245.48.1 is inside the published 173.245.48.0/20 CF range.
        $request = $this->makeRequest('173.245.48.1', '198.51.100.7');

        $this->assertSame('198.51.100.7', resolve_client_ip($request));
    }

    public function testResolveClientIpIgnoresCfConnectingIpWhenRemoteAddrIsNotCloudflare(): void
    {
        // Anti-spoof: a direct client hitting the origin cannot forge a CF
        // source IP, so a spoofed CF-Connecting-IP header must be ignored.
        $request = $this->makeRequest('203.0.113.9', '10.0.0.1');

        $this->assertSame('203.0.113.9', resolve_client_ip($request));
    }

    public function testResolveClientIpFallsBackToRemoteAddrOnCloudflareEdgeWithoutHeader(): void
    {
        $request = $this->makeRequest('173.245.48.1');

        $this->assertSame('173.245.48.1', resolve_client_ip($request));
    }

    public function testResolveClientIpFallsBackToRemoteAddrOnCloudflareEdgeWithGarbageHeader(): void
    {
        $request = $this->makeRequest('173.245.48.1', 'not-an-ip');

        $this->assertSame('173.245.48.1', resolve_client_ip($request));
    }
}
