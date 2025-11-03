<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\Analyzer;

use Diffalyzer\Analyzer\DependencyAnalyzer;
use Diffalyzer\Strategy\ConservativeStrategy;
use Diffalyzer\Strategy\MinimalStrategy;
use PHPUnit\Framework\TestCase;

final class DependencyAnalyzerTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/diffalyzer_analyzer_test_' . uniqid();
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

    private function createPhpFile(string $relativePath, string $content): void
    {
        $fullPath = $this->tempDir . '/' . $relativePath;
        $dir = dirname($fullPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($fullPath, $content);
    }

    public function testBuildsDependencyGraph(): void
    {
        $this->createPhpFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {
    public function getName(): string {
        return 'test';
    }
}
PHP
        );

        $this->createPhpFile('src/UserCollector.php', <<<'PHP'
<?php
namespace App;

use App\User;

class UserCollector {
    public function collect(User $user): void {}
}
PHP
        );

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph(['src/User.php', 'src/UserCollector.php']);

        $affectedFiles = $analyzer->getAffectedFiles(['src/User.php']);

        $this->assertContains('src/User.php', $affectedFiles);
        $this->assertContains('src/UserCollector.php', $affectedFiles);
    }

    public function testTransitiveDependencies(): void
    {
        $this->createPhpFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {}
PHP
        );

        $this->createPhpFile('src/UserCollector.php', <<<'PHP'
<?php
namespace App;

use App\User;

class UserCollector {
    public function collect(User $user): void {}
}
PHP
        );

        $this->createPhpFile('src/UserService.php', <<<'PHP'
<?php
namespace App;

use App\UserCollector;

class UserService {
    private UserCollector $collector;
}
PHP
        );

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph([
            'src/User.php',
            'src/UserCollector.php',
            'src/UserService.php'
        ]);

        $affectedFiles = $analyzer->getAffectedFiles(['src/User.php']);

        $this->assertContains('src/User.php', $affectedFiles);
        $this->assertContains('src/UserCollector.php', $affectedFiles);
        $this->assertContains('src/UserService.php', $affectedFiles);
    }

    public function testFindsTestsThatImportChangedClasses(): void
    {
        $this->createPhpFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {}
PHP
        );

        $this->createPhpFile('tests/UserTest.php', <<<'PHP'
<?php
namespace App\Tests;

use App\User;

class UserTest {
    public function testUser(): void {
        $user = new User();
    }
}
PHP
        );

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph(['src/User.php', 'tests/UserTest.php']);

        $affectedFiles = $analyzer->getAffectedFiles(['src/User.php']);

        $this->assertContains('tests/UserTest.php', $affectedFiles);
    }

    public function testNoAffectedFilesWhenIndependent(): void
    {
        $this->createPhpFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {}
PHP
        );

        $this->createPhpFile('src/Product.php', <<<'PHP'
<?php
namespace App;

class Product {}
PHP
        );

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph(['src/User.php', 'src/Product.php']);

        $affectedFiles = $analyzer->getAffectedFiles(['src/User.php']);

        $this->assertContains('src/User.php', $affectedFiles);
        $this->assertNotContains('src/Product.php', $affectedFiles);
    }

    public function testClassToFileMapping(): void
    {
        $this->createPhpFile('src/Models/User.php', <<<'PHP'
<?php
namespace App\Models;

class User {}
PHP
        );

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph(['src/Models/User.php']);

        // Change to User.php should affect itself
        $affectedFiles = $analyzer->getAffectedFiles(['src/Models/User.php']);
        $this->assertContains('src/Models/User.php', $affectedFiles);
    }

    public function testMultipleClassesInSameFile(): void
    {
        $this->createPhpFile('src/Models.php', <<<'PHP'
<?php
namespace App;

class User {}
class Admin {}
PHP
        );

        $this->createPhpFile('src/UserService.php', <<<'PHP'
<?php
namespace App;

use App\User;

class UserService {
    public function test(User $user): void {}
}
PHP
        );

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph(['src/Models.php', 'src/UserService.php']);

        $affectedFiles = $analyzer->getAffectedFiles(['src/Models.php']);

        $this->assertContains('src/Models.php', $affectedFiles);
        $this->assertContains('src/UserService.php', $affectedFiles);
    }

    public function testHandlesInterfaceDependencies(): void
    {
        $this->createPhpFile('src/UserInterface.php', <<<'PHP'
<?php
namespace App;

interface UserInterface {}
PHP
        );

        $this->createPhpFile('src/User.php', <<<'PHP'
<?php
namespace App;

use App\UserInterface;

class User implements UserInterface {}
PHP
        );

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph(['src/UserInterface.php', 'src/User.php']);

        $affectedFiles = $analyzer->getAffectedFiles(['src/UserInterface.php']);

        $this->assertContains('src/UserInterface.php', $affectedFiles);
        $this->assertContains('src/User.php', $affectedFiles);
    }

    public function testHandlesTraitDependencies(): void
    {
        $this->createPhpFile('src/Timestampable.php', <<<'PHP'
<?php
namespace App;

trait Timestampable {}
PHP
        );

        $this->createPhpFile('src/User.php', <<<'PHP'
<?php
namespace App;

use App\Timestampable;

class User {
    use Timestampable;
}
PHP
        );

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph(['src/Timestampable.php', 'src/User.php']);

        $affectedFiles = $analyzer->getAffectedFiles(['src/Timestampable.php']);

        $this->assertContains('src/Timestampable.php', $affectedFiles);
        $this->assertContains('src/User.php', $affectedFiles);
    }

    public function testStrategyAffectsDepth(): void
    {
        $this->createPhpFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {}
PHP
        );

        $this->createPhpFile('src/UserService.php', <<<'PHP'
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

        // Conservative strategy should include instantiations
        $conservative = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $conservative->buildDependencyGraph(['src/User.php', 'src/UserService.php']);
        $affectedConservative = $conservative->getAffectedFiles(['src/User.php']);

        // Minimal strategy should only use imports, not instantiations
        $minimal = new DependencyAnalyzer($this->tempDir, new MinimalStrategy());
        $minimal->buildDependencyGraph(['src/User.php', 'src/UserService.php']);
        $affectedMinimal = $minimal->getAffectedFiles(['src/User.php']);

        // Both should find UserService because it imports User
        $this->assertContains('src/UserService.php', $affectedConservative);
        $this->assertContains('src/UserService.php', $affectedMinimal);
    }

    public function testEmptyChangedFilesReturnsEmpty(): void
    {
        $this->createPhpFile('src/User.php', <<<'PHP'
<?php
namespace App;

class User {}
PHP
        );

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph(['src/User.php']);

        $affectedFiles = $analyzer->getAffectedFiles([]);

        $this->assertEmpty($affectedFiles);
    }

    public function testCircularDependenciesHandled(): void
    {
        $this->createPhpFile('src/A.php', <<<'PHP'
<?php
namespace App;

use App\B;

class A {
    public function test(): void {
        new B();
    }
}
PHP
        );

        $this->createPhpFile('src/B.php', <<<'PHP'
<?php
namespace App;

use App\A;

class B {
    public function test(): void {
        new A();
    }
}
PHP
        );

        $analyzer = new DependencyAnalyzer($this->tempDir, new ConservativeStrategy());
        $analyzer->buildDependencyGraph(['src/A.php', 'src/B.php']);

        $affectedFiles = $analyzer->getAffectedFiles(['src/A.php']);

        // Should handle circular dependencies without infinite loop
        $this->assertContains('src/A.php', $affectedFiles);
        $this->assertContains('src/B.php', $affectedFiles);
    }
}
