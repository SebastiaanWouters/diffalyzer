<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\Git;

use Diffalyzer\Git\ChangeDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class ChangeDetectorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/diffalyzer_git_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        // Initialize git repo
        $this->runGit(['init', '-b', 'main']);
        $this->runGit(['config', 'user.email', 'test@example.com']);
        $this->runGit(['config', 'user.name', 'Test User']);

        // Create initial commit to establish HEAD
        $this->createFile('.gitkeep', '');
        $this->runGit(['add', '.gitkeep']);
        $this->runGit(['commit', '-m', 'Initial commit']);
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

    private function runGit(array $command): void
    {
        $process = new Process(array_merge(['git'], $command), $this->tempDir);
        $process->run();
    }

    private function createFile(string $path, string $content = "<?php\n"): void
    {
        $fullPath = $this->tempDir . '/' . $path;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, $content);
    }

    private function commitFile(string $path, string $content = "<?php\n"): void
    {
        $this->createFile($path, $content);
        $this->runGit(['add', $path]);
        $this->runGit(['commit', '-m', "Add $path"]);
    }

    public function testDetectsUncommittedChanges(): void
    {
        $this->commitFile('src/User.php', "<?php\nclass User {}");
        $this->createFile('src/User.php', "<?php\nclass User { /* modified */ }");

        $detector = new ChangeDetector($this->tempDir);
        $changedFiles = $detector->getChangedFiles();

        $this->assertContains('src/User.php', $changedFiles);
    }

    public function testDetectsModifiedFiles(): void
    {
        $this->commitFile('src/User.php', "<?php\nclass User {}");
        $this->createFile('src/User.php', "<?php\nclass User { /* modified */ }");

        $detector = new ChangeDetector($this->tempDir);
        $changedFiles = $detector->getChangedFiles();

        $this->assertContains('src/User.php', $changedFiles);
    }

    public function testDetectsStagedFiles(): void
    {
        $this->commitFile('src/User.php');
        $this->createFile('src/NewFile.php');
        $this->runGit(['add', 'src/NewFile.php']);

        $detector = new ChangeDetector($this->tempDir);
        $changedFiles = $detector->getChangedFiles(null, null, true);

        $this->assertContains('src/NewFile.php', $changedFiles);
    }

    public function testOnlyReturnsPhpFiles(): void
    {
        $this->commitFile('src/User.php', "<?php\nclass User {}");
        $this->commitFile('README.md', '# Test');
        $this->commitFile('composer.json', '{}');

        // Modify all files
        $this->createFile('src/User.php', "<?php\nclass User { /* modified */ }");
        $this->createFile('README.md', '# Test Modified');
        $this->createFile('composer.json', '{"modified": true}');

        $detector = new ChangeDetector($this->tempDir);
        $changedFiles = $detector->getChangedFiles();

        $this->assertContains('src/User.php', $changedFiles);
        $this->assertNotContains('README.md', $changedFiles);
        $this->assertNotContains('composer.json', $changedFiles);
    }

    public function testCompareBranches(): void
    {
        $this->commitFile('src/User.php');

        $this->runGit(['checkout', '-b', 'feature']);
        $this->commitFile('src/Feature.php');

        $detector = new ChangeDetector($this->tempDir);
        $changedFiles = $detector->getChangedFiles('main', 'feature');

        $this->assertContains('src/Feature.php', $changedFiles);
        $this->assertNotContains('src/User.php', $changedFiles);
    }

    public function testCompareFromBranchToHead(): void
    {
        $this->commitFile('src/User.php');

        $this->runGit(['checkout', '-b', 'feature']);
        $this->commitFile('src/Feature.php');

        $detector = new ChangeDetector($this->tempDir);
        $changedFiles = $detector->getChangedFiles('main');

        $this->assertContains('src/Feature.php', $changedFiles);
    }

    public function testNoChangesReturnsEmptyArray(): void
    {
        // Already has initial commit, no new changes
        $detector = new ChangeDetector($this->tempDir);
        $changedFiles = $detector->getChangedFiles();

        $this->assertEmpty($changedFiles);
    }

    public function testDeletedFilesNotIncluded(): void
    {
        $this->commitFile('src/User.php');
        unlink($this->tempDir . '/src/User.php');

        $detector = new ChangeDetector($this->tempDir);
        $changedFiles = $detector->getChangedFiles();

        // Deleted files should not be in the list
        $this->assertEmpty($changedFiles);
    }

    public function testNestedPhpFiles(): void
    {
        $this->commitFile('src/User.php');
        $this->commitFile('src/Models/Product.php');
        $this->commitFile('src/Services/User/UserService.php');

        // Modify nested files
        $this->createFile('src/Models/Product.php', "<?php\nclass Product { /* modified */ }");
        $this->createFile('src/Services/User/UserService.php', "<?php\nclass UserService { /* modified */ }");

        $detector = new ChangeDetector($this->tempDir);
        $changedFiles = $detector->getChangedFiles();

        $this->assertContains('src/Models/Product.php', $changedFiles);
        $this->assertContains('src/Services/User/UserService.php', $changedFiles);
    }

    public function testFiltersNonExistentFiles(): void
    {
        $this->commitFile('src/User.php');
        $this->createFile('src/Temp.php');
        $this->runGit(['add', 'src/Temp.php']);

        // Remove file after staging
        unlink($this->tempDir . '/src/Temp.php');

        $detector = new ChangeDetector($this->tempDir);
        $changedFiles = $detector->getChangedFiles();

        $this->assertNotContains('src/Temp.php', $changedFiles);
    }
}
