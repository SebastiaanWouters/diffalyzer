<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\Formatter;

use Diffalyzer\Formatter\PhpUnitFormatter;
use PHPUnit\Framework\TestCase;

final class PhpUnitFormatterTest extends TestCase
{
    private PhpUnitFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new PhpUnitFormatter('/project/root');
    }

    public function testFullScanReturnsEmptyString(): void
    {
        $files = ['src/User.php', 'tests/UserTest.php'];
        $result = $this->formatter->format($files, true);

        $this->assertSame('', $result);
    }

    public function testFiltersOnlyTestFiles(): void
    {
        $files = ['src/User.php', 'tests/UserTest.php', 'src/UserCollector.php'];
        $result = $this->formatter->format($files, false);

        $this->assertSame('tests/UserTest.php', $result);
    }

    public function testMultipleTestFiles(): void
    {
        $files = [
            'src/User.php',
            'tests/UserTest.php',
            'tests/UserCollectorTest.php',
            'src/UserCollector.php'
        ];
        $result = $this->formatter->format($files, false);

        $this->assertSame('tests/UserTest.php tests/UserCollectorTest.php', $result);
    }

    public function testNoTestFilesReturnsEmptyString(): void
    {
        $files = ['src/User.php', 'src/UserCollector.php'];
        $result = $this->formatter->format($files, false);

        $this->assertSame('', $result);
    }

    public function testEmptyFilesReturnsEmptyString(): void
    {
        $result = $this->formatter->format([], false);

        $this->assertSame('', $result);
    }

    public function testTestDirectoryVariations(): void
    {
        $files = [
            'tests/UserTest.php',
            'test/FooTest.php',
            'Tests/BarTest.php',
            'Test/BazTest.php',
            'src/User.php'
        ];
        $result = $this->formatter->format($files, false);

        $this->assertStringContainsString('tests/UserTest.php', $result);
        $this->assertStringContainsString('test/FooTest.php', $result);
        $this->assertStringContainsString('Tests/BarTest.php', $result);
        $this->assertStringContainsString('Test/BazTest.php', $result);
        $this->assertStringNotContainsString('src/User.php', $result);
    }

    public function testTestFileByNameOnly(): void
    {
        $files = ['integration/UserIntegrationTest.php', 'src/User.php'];
        $result = $this->formatter->format($files, false);

        $this->assertSame('integration/UserIntegrationTest.php', $result);
    }

    public function testNestedTestDirectories(): void
    {
        $files = [
            'tests/Unit/UserTest.php',
            'tests/Integration/User/UserFlowTest.php',
            'src/User.php'
        ];
        $result = $this->formatter->format($files, false);

        $this->assertStringContainsString('tests/Unit/UserTest.php', $result);
        $this->assertStringContainsString('tests/Integration/User/UserFlowTest.php', $result);
    }

    public function testFileWithTestInMiddleOfName(): void
    {
        $files = ['src/TestHelper.php', 'tests/UserTest.php'];
        $result = $this->formatter->format($files, false);

        $this->assertStringContainsString('tests/UserTest.php', $result);
        // src/TestHelper.php should NOT be included (not a test file)
        $this->assertStringNotContainsString('src/TestHelper.php', $result);
    }

    public function testSpecDirectory(): void
    {
        $files = ['spec/UserSpec.php', 'src/User.php'];
        $result = $this->formatter->format($files, false);

        // spec files don't match our test patterns
        $this->assertSame('', $result);
    }
}
