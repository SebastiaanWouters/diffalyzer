<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\Visitor;

use Diffalyzer\Visitor\DependencyVisitor;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class DependencyVisitorTest extends TestCase
{
    private function parseAndVisit(string $code): DependencyVisitor
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $ast = $parser->parse($code);

        $visitor = new DependencyVisitor();
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($ast);

        return $visitor;
    }

    public function testExtractsUseStatements(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\Bar;
use Baz\Qux;

class MyClass {}
PHP;

        $visitor = $this->parseAndVisit($code);
        $uses = $visitor->getUses();

        $this->assertContains('Foo\Bar', $uses);
        $this->assertContains('Baz\Qux', $uses);
        $this->assertCount(2, $uses);
    }

    public function testExtractsGroupUseStatements(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\{Bar, Baz};

class MyClass {}
PHP;

        $visitor = $this->parseAndVisit($code);
        $uses = $visitor->getUses();

        $this->assertContains('Foo\Bar', $uses);
        $this->assertContains('Foo\Baz', $uses);
    }

    public function testExtractsDeclaredClass(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Models;

class User {}
PHP;

        $visitor = $this->parseAndVisit($code);
        $declared = $visitor->getDeclaredClasses();

        $this->assertContains('App\Models\User', $declared);
    }

    public function testExtractsDeclaredInterface(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Contracts;

interface UserInterface {}
PHP;

        $visitor = $this->parseAndVisit($code);
        $declared = $visitor->getDeclaredClasses();

        $this->assertContains('App\Contracts\UserInterface', $declared);
    }

    public function testExtractsDeclaredTrait(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Traits;

trait Timestampable {}
PHP;

        $visitor = $this->parseAndVisit($code);
        $declared = $visitor->getDeclaredClasses();

        $this->assertContains('App\Traits\Timestampable', $declared);
    }

    public function testExtractsClassExtends(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\Bar;

class MyClass extends Bar {}
PHP;

        $visitor = $this->parseAndVisit($code);
        $extends = $visitor->getExtends();

        $this->assertContains('Bar', $extends);
    }

    public function testExtractsClassImplements(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\BarInterface;
use Foo\BazInterface;

class MyClass implements BarInterface, BazInterface {}
PHP;

        $visitor = $this->parseAndVisit($code);
        $implements = $visitor->getImplements();

        $this->assertContains('BarInterface', $implements);
        $this->assertContains('BazInterface', $implements);
    }

    public function testExtractsInterfaceExtends(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\BaseInterface;

interface MyInterface extends BaseInterface {}
PHP;

        $visitor = $this->parseAndVisit($code);
        $extends = $visitor->getExtends();

        $this->assertContains('BaseInterface', $extends);
    }

    public function testExtractsTraitUse(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\TimestampTrait;

class MyClass {
    use TimestampTrait;
}
PHP;

        $visitor = $this->parseAndVisit($code);
        $traits = $visitor->getTraits();

        $this->assertContains('TimestampTrait', $traits);
    }

    public function testExtractsInstantiations(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\Bar;

class MyClass {
    public function test() {
        $obj = new Bar();
    }
}
PHP;

        $visitor = $this->parseAndVisit($code);
        $instantiations = $visitor->getInstantiations();

        $this->assertContains('Bar', $instantiations);
    }

    public function testExtractsStaticCalls(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\Bar;

class MyClass {
    public function test() {
        Bar::doSomething();
    }
}
PHP;

        $visitor = $this->parseAndVisit($code);
        $staticCalls = $visitor->getStaticCalls();

        $this->assertContains('Bar', $staticCalls);
    }

    public function testExtractsMethodCallVariables(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass {
    public function test() {
        $foo->doSomething();
        $bar->doOther();
    }
}
PHP;

        $visitor = $this->parseAndVisit($code);
        $methodCalls = $visitor->getMethodCalls();

        $this->assertContains('foo', $methodCalls);
        $this->assertContains('bar', $methodCalls);
    }

    public function testResolvesFullyQualifiedNames(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

class MyClass extends \Fully\Qualified\ClassName {}
PHP;

        $visitor = $this->parseAndVisit($code);
        $extends = $visitor->getExtends();

        $this->assertContains('Fully\Qualified\ClassName', $extends);
    }

    public function testHandlesNoNamespace(): void
    {
        $code = <<<'PHP'
<?php
class MyClass {}
PHP;

        $visitor = $this->parseAndVisit($code);
        $declared = $visitor->getDeclaredClasses();

        $this->assertContains('MyClass', $declared);
    }

    public function testDeduplicatesDependencies(): void
    {
        $code = <<<'PHP'
<?php
namespace App;

use Foo\Bar;

class MyClass {
    public function test() {
        new Bar();
        new Bar();
        Bar::method();
    }
}
PHP;

        $visitor = $this->parseAndVisit($code);
        $instantiations = $visitor->getInstantiations();
        $staticCalls = $visitor->getStaticCalls();

        $this->assertCount(1, $instantiations);
        $this->assertCount(1, $staticCalls);
    }

    public function testComplexClassWithAllFeatures(): void
    {
        $code = <<<'PHP'
<?php
namespace App\Models;

use App\Contracts\UserInterface;
use App\Traits\Timestampable;
use App\Services\EmailService;
use Illuminate\Database\Eloquent\Model;

class User extends Model implements UserInterface
{
    use Timestampable;

    public function sendEmail(): void
    {
        $service = new EmailService();
        EmailService::configure();
        $service->send();
    }
}
PHP;

        $visitor = $this->parseAndVisit($code);

        $this->assertContains('App\Models\User', $visitor->getDeclaredClasses());
        $this->assertContains('Model', $visitor->getExtends());
        $this->assertContains('UserInterface', $visitor->getImplements());
        $this->assertContains('Timestampable', $visitor->getTraits());
        $this->assertContains('EmailService', $visitor->getInstantiations());
        $this->assertContains('EmailService', $visitor->getStaticCalls());

        $uses = $visitor->getUses();
        $this->assertContains('App\Contracts\UserInterface', $uses);
        $this->assertContains('App\Traits\Timestampable', $uses);
        $this->assertContains('App\Services\EmailService', $uses);
        $this->assertContains('Illuminate\Database\Eloquent\Model', $uses);
    }
}
