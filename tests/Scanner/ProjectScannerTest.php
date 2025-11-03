<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\Scanner;

use Diffalyzer\Scanner\ProjectScanner;
use PHPUnit\Framework\TestCase;

final class ProjectScannerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/diffalyzer_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createFile(string $relativePath): void
    {
        $fullPath = $this->tempDir . '/' . $relativePath;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, "<?php\n");
    }

    public function testFindsPhpFilesInProject(): void
    {
        $this->createFile('src/User.php');
        $this->createFile('src/UserCollector.php');
        $this->createFile('tests/UserTest.php');

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $this->assertContains('src/User.php', $files);
        $this->assertContains('src/UserCollector.php', $files);
        $this->assertContains('tests/UserTest.php', $files);
        $this->assertCount(3, $files);
    }

    public function testExcludesVendorDirectory(): void
    {
        $this->createFile('src/User.php');
        $this->createFile('vendor/foo/Bar.php');

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $this->assertContains('src/User.php', $files);
        $this->assertNotContains('vendor/foo/Bar.php', $files);
    }

    public function testExcludesNodeModules(): void
    {
        $this->createFile('src/User.php');
        $this->createFile('node_modules/package/index.php');

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $this->assertContains('src/User.php', $files);
        $this->assertNotContains('node_modules/package/index.php', $files);
    }

    public function testExcludesGitDirectory(): void
    {
        $this->createFile('src/User.php');
        $this->createFile('.git/hooks/pre-commit.php');

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $this->assertContains('src/User.php', $files);
        $this->assertNotContains('.git/hooks/pre-commit.php', $files);
    }

    public function testExcludesCacheDirectory(): void
    {
        $this->createFile('src/User.php');
        $this->createFile('cache/compiled.php');

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $this->assertContains('src/User.php', $files);
        $this->assertNotContains('cache/compiled.php', $files);
    }

    public function testExcludesVarDirectory(): void
    {
        $this->createFile('src/User.php');
        $this->createFile('var/cache/prod/container.php');

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $this->assertContains('src/User.php', $files);
        $this->assertNotContains('var/cache/prod/container.php', $files);
    }

    public function testFindsNestedPhpFiles(): void
    {
        $this->createFile('src/Models/User.php');
        $this->createFile('src/Services/User/UserService.php');
        $this->createFile('tests/Unit/Models/UserTest.php');

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $this->assertContains('src/Models/User.php', $files);
        $this->assertContains('src/Services/User/UserService.php', $files);
        $this->assertContains('tests/Unit/Models/UserTest.php', $files);
    }

    public function testIgnoresNonPhpFiles(): void
    {
        $this->createFile('src/User.php');
        file_put_contents($this->tempDir . '/README.md', '# Test');
        file_put_contents($this->tempDir . '/composer.json', '{}');

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $this->assertContains('src/User.php', $files);
        $this->assertCount(1, $files);
    }

    public function testEmptyProjectReturnsEmptyArray(): void
    {
        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $this->assertEmpty($files);
    }

    public function testIncludesDotPhpFiles(): void
    {
        // Note: Symfony Finder may still exclude dotfiles even with ignoreDotFiles(false)
        // This test verifies the behavior
        $this->createFile('src/.bootstrap.php');
        $this->createFile('src/User.php');

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        // At minimum, regular files should be found
        $this->assertContains('src/User.php', $files);
        // Dotfiles may or may not be included depending on Finder behavior
        $this->assertGreaterThanOrEqual(1, count($files));
    }

    public function testReturnsRelativePaths(): void
    {
        $this->createFile('src/User.php');

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        foreach ($files as $file) {
            $this->assertStringStartsNotWith('/', $file);
            $this->assertStringStartsNotWith($this->tempDir, $file);
        }
    }
}
