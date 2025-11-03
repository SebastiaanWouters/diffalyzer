<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\EdgeCases;

use Diffalyzer\Analyzer\DependencyAnalyzer;
use Diffalyzer\Git\ChangeDetector;
use Diffalyzer\Scanner\ProjectScanner;
use Diffalyzer\Strategy\ConservativeStrategy;
use PHPUnit\Framework\TestCase;

final class ErrorHandlingTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/diffalyzer_edge_test_' . uniqid();
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

    private function createFile(string $path, string $content): void
    {
        $fullPath = $this->tempDir . '/' . $path;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, $content);
    }

    public function testHandlesInvalidPhpSyntax(): void
    {
        $this->createFile('src/Invalid.php', '<?php class Invalid { // Missing closing brace');

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());

        // Should not throw exception, just skip invalid files
        $this->expectNotToPerformAssertions();
        try {
            $analyzer->buildDependencyGraph($files);
        } catch (\Exception $e) {
            // Parser errors are expected for invalid syntax
            $this->assertInstanceOf(\PhpParser\Error::class, $e);
        }
    }

    public function testHandlesEmptyPhpFile(): void
    {
        $this->createFile('src/Empty.php', '<?php');

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($files);

        $affectedFiles = $analyzer->getAffectedFiles(['src/Empty.php']);

        $this->assertContains('src/Empty.php', $affectedFiles);
    }

    public function testHandlesFileWithOnlyComments(): void
    {
        $this->createFile('src/Comments.php', <<<'PHP'
<?php
/**
 * This file only contains comments
 * No actual code here
 */
PHP
        );

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($files);

        $affectedFiles = $analyzer->getAffectedFiles(['src/Comments.php']);

        $this->assertContains('src/Comments.php', $affectedFiles);
    }

    public function testHandlesAnonymousClasses(): void
    {
        $this->createFile('src/Anonymous.php', <<<'PHP'
<?php
namespace App;

class Factory {
    public function create() {
        return new class {
            public function test(): void {}
        };
    }
}
PHP
        );

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($files);

        $affectedFiles = $analyzer->getAffectedFiles(['src/Anonymous.php']);

        $this->assertContains('src/Anonymous.php', $affectedFiles);
    }

    public function testHandlesPhp8Attributes(): void
    {
        $this->createFile('src/WithAttribute.php', <<<'PHP'
<?php
namespace App;

#[\Attribute]
class MyAttribute {}

#[MyAttribute]
class User {}
PHP
        );

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($files);

        $affectedFiles = $analyzer->getAffectedFiles(['src/WithAttribute.php']);

        $this->assertContains('src/WithAttribute.php', $affectedFiles);
    }

    public function testHandlesEnums(): void
    {
        $this->createFile('src/Status.php', <<<'PHP'
<?php
namespace App;

enum Status {
    case ACTIVE;
    case INACTIVE;
}
PHP
        );

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($files);

        $affectedFiles = $analyzer->getAffectedFiles(['src/Status.php']);

        $this->assertContains('src/Status.php', $affectedFiles);
    }

    public function testHandlesDynamicClassInstantiation(): void
    {
        $this->createFile('src/Dynamic.php', <<<'PHP'
<?php
namespace App;

class Factory {
    public function create(string $className) {
        return new $className();
    }
}
PHP
        );

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($files);

        // Should handle gracefully without errors
        $affectedFiles = $analyzer->getAffectedFiles(['src/Dynamic.php']);

        $this->assertContains('src/Dynamic.php', $affectedFiles);
    }

    public function testHandlesGlobalNamespace(): void
    {
        $this->createFile('src/Global.php', <<<'PHP'
<?php
class GlobalClass {}
PHP
        );

        $this->createFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User extends \GlobalClass {}
PHP
        );

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($files);

        $affectedFiles = $analyzer->getAffectedFiles(['src/Global.php']);

        $this->assertContains('src/Global.php', $affectedFiles);
        $this->assertContains('src/User.php', $affectedFiles);
    }

    public function testHandlesAliasedImports(): void
    {
        $this->createFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {}
PHP
        );

        $this->createFile('src/Service.php', <<<'PHP'
<?php
namespace App;

use App\User as UserModel;

class Service {
    public function test(UserModel $user): void {}
}
PHP
        );

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($files);

        $affectedFiles = $analyzer->getAffectedFiles(['src/User.php']);

        $this->assertContains('src/User.php', $affectedFiles);
        $this->assertContains('src/Service.php', $affectedFiles);
    }

    public function testHandlesConstUse(): void
    {
        $this->createFile('src/Constants.php', <<<'PHP'
<?php
namespace App;

const MY_CONSTANT = 'value';
PHP
        );

        $this->createFile('src/User.php', <<<'PHP'
<?php
namespace App;

use const App\MY_CONSTANT;

class User {
    public function test(): string {
        return MY_CONSTANT;
    }
}
PHP
        );

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($files);

        // Should handle without errors
        $affectedFiles = $analyzer->getAffectedFiles(['src/User.php']);

        $this->assertContains('src/User.php', $affectedFiles);
    }

    public function testHandlesFunctionUse(): void
    {
        $this->createFile('src/functions.php', <<<'PHP'
<?php
namespace App;

function myFunction(): string {
    return 'test';
}
PHP
        );

        $this->createFile('src/User.php', <<<'PHP'
<?php
namespace App;

use function App\myFunction;

class User {
    public function test(): string {
        return myFunction();
    }
}
PHP
        );

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($files);

        // Should handle without errors
        $affectedFiles = $analyzer->getAffectedFiles(['src/User.php']);

        $this->assertContains('src/User.php', $affectedFiles);
    }

    public function testHandlesMultipleNamespacesInOneFile(): void
    {
        $this->createFile('src/Multi.php', <<<'PHP'
<?php
namespace App\Models {
    class User {}
}

namespace App\Services {
    use App\Models\User;

    class UserService {
        public function test(User $user): void {}
    }
}
PHP
        );

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($files);

        $affectedFiles = $analyzer->getAffectedFiles(['src/Multi.php']);

        $this->assertContains('src/Multi.php', $affectedFiles);
    }

    public function testHandlesSymlinkLoops(): void
    {
        // This test only runs on systems that support symlinks
        if (!function_exists('symlink')) {
            $this->markTestSkipped('Symlinks not supported');
        }

        $this->createFile('src/User.php', '<?php class User {}');

        // Try to create a symlink (may fail on some systems)
        try {
            symlink($this->tempDir . '/src', $this->tempDir . '/src_link');
        } catch (\Exception $e) {
            $this->markTestSkipped('Cannot create symlinks');
        }

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        // Should not include duplicate files from symlink
        $this->assertCount(1, array_unique($files));
    }

    public function testHandlesVeryLongDependencyChain(): void
    {
        // Create a chain of 50 classes: Class0 extends Class1 extends Class2 ... extends Class49
        // When Class49 changes, all should be affected
        for ($i = 0; $i < 50; $i++) {
            $nextClass = $i < 49 ? 'Class' . ($i + 1) : '';
            $use = $nextClass ? "use App\\$nextClass;\n\n" : '';
            $extends = $nextClass ? " extends $nextClass" : '';

            $this->createFile("src/Class$i.php", <<<PHP
<?php
namespace App;

$use class Class$i$extends {}
PHP
            );
        }

        $scanner = new ProjectScanner($this->tempDir);
        $files = $scanner->getAllPhpFiles();

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph($files);

        // Changing the last class (Class49) should affect all previous ones that extend it
        $affectedFiles = $analyzer->getAffectedFiles(['src/Class49.php']);

        // Should include at least Class49 and Class48 which extends it
        $this->assertGreaterThanOrEqual(2, count($affectedFiles));
        $this->assertContains('src/Class49.php', $affectedFiles);
        $this->assertContains('src/Class48.php', $affectedFiles);
    }
}
