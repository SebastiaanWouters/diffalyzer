<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\Formatter;

use Diffalyzer\Formatter\DefaultFormatter;
use PHPUnit\Framework\TestCase;

final class DefaultFormatterTest extends TestCase
{
    private DefaultFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new DefaultFormatter();
    }

    public function testFullScanReturnsEmptyString(): void
    {
        $files = ['src/User.php', 'tests/UserTest.php'];
        $result = $this->formatter->format($files, true);

        $this->assertSame('', $result);
    }

    public function testReturnsAllFiles(): void
    {
        $files = ['src/User.php', 'tests/UserTest.php', 'src/UserCollector.php'];
        $result = $this->formatter->format($files, false);

        $this->assertSame('src/User.php tests/UserTest.php src/UserCollector.php', $result);
    }

    public function testEmptyFilesReturnsEmptyString(): void
    {
        $result = $this->formatter->format([], false);

        $this->assertSame('', $result);
    }

    public function testSingleFile(): void
    {
        $files = ['src/User.php'];
        $result = $this->formatter->format($files, false);

        $this->assertSame('src/User.php', $result);
    }

    public function testManyFiles(): void
    {
        $files = [
            'src/User.php',
            'src/UserCollector.php',
            'src/Services/UserService.php',
            'tests/UserTest.php',
            'tests/Services/UserServiceTest.php'
        ];
        $result = $this->formatter->format($files, false);

        $expected = implode(' ', $files);
        $this->assertSame($expected, $result);
    }
}
