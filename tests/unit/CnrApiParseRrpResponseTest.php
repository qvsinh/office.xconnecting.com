<?php

use App\Controllers\Api\CnrApi;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * `CnrApi::parseRrpResponse` is private (pure string parsing, no HTTP/DB
 * dependency) — invoked via reflection per the plan's guidance.
 *
 * @internal
 */
final class CnrApiParseRrpResponseTest extends CIUnitTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function parse(string $body): array
    {
        $method = new ReflectionMethod(CnrApi::class, 'parseRrpResponse');
        $method->setAccessible(true);

        return $method->invoke(new CnrApi(), $body);
    }

    public function testParsesKeyValueLines(): void
    {
        $result = $this->parse("code=200\ndescription=Command completed successfully\n");

        $this->assertSame('200', $result['code']);
        $this->assertSame('Command completed successfully', $result['description']);
    }

    public function testFoldsPropertyIndexedArraysIntoPropertyKey(): void
    {
        $result = $this->parse("code=200\nproperty[status][0]=ACTIVE\nproperty[status][1]=LOCKED\n");

        $this->assertSame(['status' => [0 => 'ACTIVE', 1 => 'LOCKED']], $result['property']);
    }

    public function testFoldsMultiplePropertyNamesSeparately(): void
    {
        $result = $this->parse("property[nameserver][0]=ns1.example.com\nproperty[status][0]=ACTIVE\n");

        $this->assertSame('ns1.example.com', $result['property']['nameserver'][0]);
        $this->assertSame('ACTIVE', $result['property']['status'][0]);
    }

    public function testIgnoresLinesWithoutAnEqualsSign(): void
    {
        $result = $this->parse("code=200\nnot-a-kv-line\ndescription=OK\n");

        $this->assertArrayNotHasKey('not-a-kv-line', $result);
        $this->assertSame('OK', $result['description']);
    }

    public function testTrimsKeyAndValueWhitespace(): void
    {
        $result = $this->parse(" code = 200 \n");

        $this->assertSame('200', $result['code']);
    }
}
