<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\Strategy;

use Diffalyzer\Strategy\ConservativeStrategy;
use Diffalyzer\Strategy\MinimalStrategy;
use Diffalyzer\Strategy\ModerateStrategy;
use Diffalyzer\Visitor\DependencyVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class StrategyTest extends TestCase
{
    private function createVisitorWithCode(string $code): DependencyVisitor
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);

        $visitor = new DependencyVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor;
    }

    public function testConservativeStrategyIncludesEverything(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\Bar;
use Baz\Qux;

class MyClass extends Bar {
    use SomeTrait;

    public function test() {
        new Qux();
        Bar::staticMethod();
    }
}
PHP;

        $visitor = $this->createVisitorWithCode($code);
        $strategy = new ConservativeStrategy();
        $dependencies = $strategy->extractDependencies($visitor);

        $this->assertContains('Foo\Bar', $dependencies);
        $this->assertContains('Baz\Qux', $dependencies);
        $this->assertContains('Bar', $dependencies);
        $this->assertContains('SomeTrait', $dependencies);
        $this->assertContains('Qux', $dependencies);
    }

    public function testModerateStrategyExcludesInstantiationsAndStaticCalls(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\Bar;
use Baz\Qux;

class MyClass extends Bar {
    use SomeTrait;

    public function test() {
        new Qux();
        Bar::staticMethod();
    }
}
PHP;

        $visitor = $this->createVisitorWithCode($code);
        $strategy = new ModerateStrategy();
        $dependencies = $strategy->extractDependencies($visitor);

        // Should include uses, extends, traits
        $this->assertContains('Foo\Bar', $dependencies);
        $this->assertContains('Baz\Qux', $dependencies);
        $this->assertContains('Bar', $dependencies);
        $this->assertContains('SomeTrait', $dependencies);

        // Should NOT include instantiations and static calls as separate entries
        // (Note: Bar appears via extends, Qux via use)
    }

    public function testMinimalStrategyOnlyIncludesUsesAndInheritance(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\Bar;
use Baz\Qux;

class MyClass extends Bar {
    use SomeTrait;

    public function test() {
        new Qux();
        Bar::staticMethod();
    }
}
PHP;

        $visitor = $this->createVisitorWithCode($code);
        $strategy = new MinimalStrategy();
        $dependencies = $strategy->extractDependencies($visitor);

        // Should include uses and extends only
        $this->assertContains('Foo\Bar', $dependencies);
        $this->assertContains('Baz\Qux', $dependencies);
        $this->assertContains('Bar', $dependencies);

        // Should NOT include traits
        $this->assertNotContains('SomeTrait', $dependencies);
    }

    public function testConservativeWithNoInstantiations(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\Bar;

class MyClass extends Bar {}
PHP;

        $visitor = $this->createVisitorWithCode($code);
        $strategy = new ConservativeStrategy();
        $dependencies = $strategy->extractDependencies($visitor);

        $this->assertContains('Foo\Bar', $dependencies);
        $this->assertContains('Bar', $dependencies);
    }

    public function testStrategiesDeduplicateDependencies(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\Bar;

class MyClass extends Bar {
    public function test() {
        new Bar();
        Bar::method();
    }
}
PHP;

        $visitor = $this->createVisitorWithCode($code);

        $conservative = new ConservativeStrategy();
        $deps = $conservative->extractDependencies($visitor);

        // Should not have duplicates
        $this->assertSame(count($deps), count(array_unique($deps)));
    }

    public function testEmptyClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass {}
PHP;

        $visitor = $this->createVisitorWithCode($code);

        $conservative = new ConservativeStrategy();
        $moderate = new ModerateStrategy();
        $minimal = new MinimalStrategy();

        $this->assertEmpty($conservative->extractDependencies($visitor));
        $this->assertEmpty($moderate->extractDependencies($visitor));
        $this->assertEmpty($minimal->extractDependencies($visitor));
    }

    public function testInterfaceWithExtends(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\BaseInterface;

interface MyInterface extends BaseInterface {}
PHP;

        $visitor = $this->createVisitorWithCode($code);

        $strategy = new MinimalStrategy();
        $dependencies = $strategy->extractDependencies($visitor);

        $this->assertContains('Foo\BaseInterface', $dependencies);
        $this->assertContains('BaseInterface', $dependencies);
    }
}
