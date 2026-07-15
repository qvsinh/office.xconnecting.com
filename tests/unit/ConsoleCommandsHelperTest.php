<?php

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ConsoleCommandsHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        helper('console_commands');
    }

    public function testCnrReturnsNonEmptyGroupedCommands(): void
    {
        $groups = console_commands('cnr');

        $this->assertNotEmpty($groups);
        $this->assertContainsOnly('array', $groups);
    }

    public function testJprsReturnsNonEmptyGroupedCommands(): void
    {
        $groups = console_commands('jprs');

        $this->assertNotEmpty($groups);
        $this->assertContainsOnly('array', $groups);
    }

    public function testUnknownProviderReturnsEmptyArray(): void
    {
        $this->assertSame([], console_commands('bogus'));
    }

    public function testCnrContainsCheckDomainWithExpectedShape(): void
    {
        $entry = $this->findCommand(console_commands('cnr'), 'CheckDomain');

        $this->assertNotNull($entry);
        $this->assertSame('CheckDomain', $entry['name']);
        $this->assertIsString($entry['description']);
        $this->assertNotSame('', $entry['description']);
        $this->assertSame(['domain' => 'example.com'], $entry['example']);
    }

    public function testJprsContainsDomainAddWithExpectedShape(): void
    {
        $entry = $this->findCommand(console_commands('jprs'), 'Domain.Add');

        $this->assertNotNull($entry);
        $this->assertSame('Domain.Add', $entry['name']);
        $this->assertIsString($entry['description']);
        $this->assertNotSame('', $entry['description']);
    }

    public function testEveryEntryHasNonEmptyNameAndStringDescription(): void
    {
        foreach (['cnr', 'jprs'] as $provider) {
            foreach (console_commands($provider) as $group => $entries) {
                foreach ($entries as $entry) {
                    $this->assertArrayHasKey('name', $entry, "missing name in {$provider}/{$group}");
                    $this->assertIsString($entry['name']);
                    $this->assertNotSame('', $entry['name']);
                    $this->assertArrayHasKey('description', $entry, "missing description for {$entry['name']}");
                    $this->assertIsString($entry['description']);
                    $this->assertNotSame('', $entry['description']);
                }
            }
        }
    }

    public function testNoDuplicateCommandNamesWithinAProvider(): void
    {
        foreach (['cnr', 'jprs'] as $provider) {
            $names = [];
            foreach (console_commands($provider) as $entries) {
                foreach ($entries as $entry) {
                    $names[] = $entry['name'];
                }
            }

            $this->assertSame($names, array_unique($names), "duplicate command name in {$provider}");
        }
    }

    /**
     * @param array<string, list<array{name: string, description: string}>> $groups
     *
     * @return array{name: string, description: string}|null
     */
    private function findCommand(array $groups, string $name): ?array
    {
        foreach ($groups as $entries) {
            foreach ($entries as $entry) {
                if ($entry['name'] === $name) {
                    return $entry;
                }
            }
        }

        return null;
    }
}
