<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\Integration;

use Diffalyzer\Analyzer\DependencyAnalyzer;
use Diffalyzer\Formatter\DefaultFormatter;
use Diffalyzer\Formatter\PhpUnitFormatter;
use Diffalyzer\Git\ChangeDetector;
use Diffalyzer\Matcher\FullScanMatcher;
use Diffalyzer\Scanner\ProjectScanner;
use Diffalyzer\Strategy\ConservativeStrategy;
use Diffalyzer\Strategy\MinimalStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class CompleteWorkflowTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/diffalyzer_integration_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);

        // Initialize git repo
        $this->runGit(['init']);
        $this->runGit(['config', 'user.email', 'test@example.com']);
        $this->runGit(['config', 'user.name', 'Test User']);
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

    private function createFile(string $path, string $content): void
    {
        $fullPath = $this->tempDir . '/' . $path;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, $content);
    }

    private function commitFile(string $path, string $content): void
    {
        $this->createFile($path, $content);
        $this->runGit(['add', $path]);
        $this->runGit(['commit', '-m', "Add $path"]);
    }

    public function testCompleteWorkflowWithPhpUnitOutput(): void
    {
        // Setup: Create initial files and commit
        $this->commitFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {
    public function getName(): string {
        return 'test';
    }
}
PHP
        );

        $this->commitFile('src/UserCollector.php', <<<'PHP'
<?php
namespace App;

use App\User;

class UserCollector {
    public function addUser(User $user): void {}
}
PHP
        );

        $this->commitFile('tests/UserTest.php', <<<'PHP'
<?php
namespace App\Tests;

use App\User;

class UserTest {
    public function testGetName(): void {
        $user = new User();
    }
}
PHP
        );

        $this->commitFile('tests/UserCollectorTest.php', <<<'PHP'
<?php
namespace App\Tests;

use App\UserCollector;

class UserCollectorTest {
    public function testAddUser(): void {
        $collector = new UserCollector();
    }
}
PHP
        );

        // Make a change to User.php
        $this->createFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {
    public function getName(): string {
        return 'modified';
    }
}
PHP
        );

        // Execute workflow
        $changeDetector = new ChangeDetector($this->tempDir);
        $changedFiles = $changeDetector->getChangedFiles();

        $scanner = new ProjectScanner($this->tempDir);
        $allPhpFiles = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($allPhpFiles);
        $affectedFiles = $analyzer->getAffectedFiles($changedFiles);

        $formatter = new PhpUnitFormatter($this->tempDir);
        $output = $formatter->format($affectedFiles, false);

        // Verify: Should output only test files
        $this->assertStringContainsString('tests/UserTest.php', $output);
        $this->assertStringContainsString('tests/UserCollectorTest.php', $output);
        $this->assertStringNotContainsString('src/User.php', $output);
        $this->assertStringNotContainsString('src/UserCollector.php', $output);
    }

    public function testCompleteWorkflowWithFilesOutput(): void
    {
        $this->commitFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {}
PHP
        );

        $this->commitFile('src/UserService.php', <<<'PHP'
<?php
namespace App;

use App\User;

class UserService {
    public function create(): User {
        return new User();
    }
}
PHP
        );

        // Change User.php
        $this->createFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {
    public string $name;
}
PHP
        );

        $changeDetector = new ChangeDetector($this->tempDir);
        $changedFiles = $changeDetector->getChangedFiles();

        $scanner = new ProjectScanner($this->tempDir);
        $allPhpFiles = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($allPhpFiles);
        $affectedFiles = $analyzer->getAffectedFiles($changedFiles);

        $formatter = new DefaultFormatter();
        $output = $formatter->format($affectedFiles, false);

        // Verify: Should output all affected files
        $this->assertStringContainsString('src/User.php', $output);
        $this->assertStringContainsString('src/UserService.php', $output);
    }

    public function testFullScanTriggerWithComposerJson(): void
    {
        $this->commitFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {}
PHP
        );

        $this->commitFile('composer.json', '{"name": "test/project"}');

        // Change composer.json
        $this->createFile('composer.json', '{"name": "test/project", "require": {}}');

        $changeDetector = new ChangeDetector($this->tempDir);
        $allChangedFiles = $changeDetector->getAllChangedFiles();

        $matcher = new FullScanMatcher();
        $shouldFullScan = $matcher->shouldTriggerFullScan($allChangedFiles, null);

        $this->assertTrue($shouldFullScan);

        // Verify formatter returns empty string for full scan
        $formatter = new PhpUnitFormatter($this->tempDir);
        $output = $formatter->format([], true);

        $this->assertSame('', $output);
    }

    public function testTransitiveDependencyChain(): void
    {
        $this->commitFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {}
PHP
        );

        $this->commitFile('src/UserCollector.php', <<<'PHP'
<?php
namespace App;

use App\User;

class UserCollector {
    public function collect(User $user): void {}
}
PHP
        );

        $this->commitFile('src/UserService.php', <<<'PHP'
<?php
namespace App;

use App\UserCollector;

class UserService {
    public function process(UserCollector $collector): void {}
}
PHP
        );

        $this->commitFile('tests/UserServiceTest.php', <<<'PHP'
<?php
namespace App\Tests;

use App\UserService;

class UserServiceTest {
    public function testProcess(): void {
        $service = new UserService();
    }
}
PHP
        );

        // Change User.php (the root of the chain)
        $this->createFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {
    public string $name;
}
PHP
        );

        $changeDetector = new ChangeDetector($this->tempDir);
        $changedFiles = $changeDetector->getChangedFiles();

        $scanner = new ProjectScanner($this->tempDir);
        $allPhpFiles = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($allPhpFiles);
        $affectedFiles = $analyzer->getAffectedFiles($changedFiles);

        // Verify full chain is affected
        $this->assertContains('src/User.php', $affectedFiles);
        $this->assertContains('src/UserCollector.php', $affectedFiles);
        $this->assertContains('src/UserService.php', $affectedFiles);
        $this->assertContains('tests/UserServiceTest.php', $affectedFiles);
    }

    public function testMinimalStrategyReducesScope(): void
    {
        $this->commitFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {}
PHP
        );

        $this->commitFile('src/UserService.php', <<<'PHP'
<?php
namespace App;

use App\User;

class UserService {
    public function create(): void {
        new User();
    }
}
PHP
        );

        $this->createFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {
    public string $name;
}
PHP
        );

        $changeDetector = new ChangeDetector($this->tempDir);
        $changedFiles = $changeDetector->getChangedFiles();

        $scanner = new ProjectScanner($this->tempDir);
        $allPhpFiles = $scanner->getAllPhpFiles();

        // Minimal strategy still finds UserService because it imports User
        $minimalAnalyzer = new DependencyAnalyzer($this->tempDir, new MinimalStrategy());
        $minimalAnalyzer->buildDependencyGraph($allPhpFiles);
        $affectedMinimal = $minimalAnalyzer->getAffectedFiles($changedFiles);

        $this->assertContains('src/User.php', $affectedMinimal);
        $this->assertContains('src/UserService.php', $affectedMinimal);
    }

    public function testNoChangesReturnsEmpty(): void
    {
        $this->commitFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {}
PHP
        );

        $changeDetector = new ChangeDetector($this->tempDir);
        $changedFiles = $changeDetector->getChangedFiles();

        $this->assertEmpty($changedFiles);

        $formatter = new PhpUnitFormatter($this->tempDir);
        $output = $formatter->format([], false);

        $this->assertSame('', $output);
    }

    public function testFixtureClassesTrackedAutomatically(): void
    {
        $this->commitFile('src/Fixtures/UserFixture.php', <<<'PHP'
<?php
namespace App\Fixtures;

class UserFixture {
    public static function create(): array {
        return ['name' => 'John'];
    }
}
PHP
        );

        $this->commitFile('tests/UserTest.php', <<<'PHP'
<?php
namespace App\Tests;

use App\Fixtures\UserFixture;

class UserTest {
    public function testUser(): void {
        $data = UserFixture::create();
    }
}
PHP
        );

        // Change fixture
        $this->createFile('src/Fixtures/UserFixture.php', <<<'PHP'
<?php
namespace App\Fixtures;

class UserFixture {
    public static function create(): array {
        return ['name' => 'Jane'];
    }
}
PHP
        );

        $changeDetector = new ChangeDetector($this->tempDir);
        $changedFiles = $changeDetector->getChangedFiles();

        $scanner = new ProjectScanner($this->tempDir);
        $allPhpFiles = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($allPhpFiles);
        $affectedFiles = $analyzer->getAffectedFiles($changedFiles);

        $formatter = new PhpUnitFormatter($this->tempDir);
        $output = $formatter->format($affectedFiles, false);

        // Fixture change should affect test that uses it
        $this->assertStringContainsString('tests/UserTest.php', $output);
    }
}
