<?php

use App\Controllers\Api\JprsApi;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * `JprsApi::buildJprsPlainPayload` / `parseJprsResponse` are private (pure
 * string building/parsing, no HTTP/DB dependency) — invoked via reflection
 * per the plan's guidance. Also covers the UTF-8<->ISO-2022-JP round trip
 * used to encode the request body / decode the response body.
 *
 * @internal
 */
final class JprsApiPayloadTest extends CIUnitTestCase
{
    /**
     * @param array<string, mixed> $payload
     */
    private function build(array $payload): string
    {
        $method = new ReflectionMethod(JprsApi::class, 'buildJprsPlainPayload');
        $method->setAccessible(true);

        return $method->invoke(new JprsApi(), $payload);
    }

    /**
     * @return array{code: ?string, description: ?string, data: array<string, mixed>, raw_response: string[]}
     */
    private function parse(string $body): array
    {
        $method = new ReflectionMethod(JprsApi::class, 'parseJprsResponse');
        $method->setAccessible(true);

        return $method->invoke(new JprsApi(), $body);
    }

    public function testBuildsCommandHeaderLineWithAsteriskPrefix(): void
    {
        $body = $this->build(['Command' => 'AuthInfo', 'DomainName' => 'example.jp']);

        $this->assertSame("*AuthInfo\nDomainName:example.jp", $body);
    }

    public function testMultiValueArraysProduceRepeatedKeyLines(): void
    {
        $body = $this->build([
            'Command'     => 'RegistTechContact',
            'NameServer'  => ['ns1.example.jp', 'ns2.example.jp'],
        ]);

        $this->assertSame("*RegistTechContact\nNameServer:ns1.example.jp\nNameServer:ns2.example.jp", $body);
    }

    public function testTrailingNewlineIsTrimmed(): void
    {
        $body = $this->build(['Command' => 'StatusDomain']);

        $this->assertSame('*StatusDomain', $body);
        $this->assertStringEndsNotWith("\n", $body);
    }

    public function testUtf8ToIso2022JpRoundTrip(): void
    {
        $body = $this->build(['Command' => 'ModifyContact', 'ContactName' => '日本語テスト']);

        $encoded = mb_convert_encoding($body, 'ISO-2022-JP', 'UTF-8');
        $decoded = mb_convert_encoding($encoded, 'UTF-8', 'ISO-2022-JP');

        $this->assertSame($body, $decoded);
        $this->assertNotSame($body, $encoded);
    }

    public function testParses3DigitStatusLineAndRepeatedKeysCollapseWithSemicolon(): void
    {
        $body = "DomainName:example.jp\nNameServer:ns1.example.jp\nNameServer:ns2.example.jp\n"
            . "200 Command completed successfully\n";

        $result = $this->parse($body);

        $this->assertSame('200', $result['code']);
        $this->assertSame('Command completed successfully', $result['description']);
        $this->assertSame('example.jp', $result['data']['DomainName']);
        $this->assertSame('ns1.example.jp;ns2.example.jp', $result['data']['NameServer']);
        $this->assertContains('200 Command completed successfully', $result['raw_response']);
    }

    public function testNonSuccessStatusLineIsExtracted(): void
    {
        $result = $this->parse("500 Internal Server Error\n");

        $this->assertSame('500', $result['code']);
        $this->assertSame('Internal Server Error', $result['description']);
    }

    public function testResponseWithoutAStatusLineHasNullCode(): void
    {
        $result = $this->parse("DomainName:example.jp\n");

        $this->assertNull($result['code']);
        $this->assertNull($result['description']);
        $this->assertSame('example.jp', $result['data']['DomainName']);
    }
}
