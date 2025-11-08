<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\Parser;

use Diffalyzer\Parser\AstBasedParser;
use Diffalyzer\Parser\TokenBasedParser;
use PHPUnit\Framework\TestCase;

/**
 * Comprehensive test comparing AST-based and Token-based parsers
 *
 * Ensures both parsers extract the same dependencies for correctness
 */
final class ParserComparisonTest extends TestCase
{
    private AstBasedParser $astParser;
    private TokenBasedParser $tokenParser;

    protected function setUp(): void
    {
        $this->astParser = new AstBasedParser();
        $this->tokenParser = new TokenBasedParser();
    }

    public function testSimpleClassWithUseStatements(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Contracts\UserInterface;

class User extends Model implements UserInterface
{
    public function create(): void
    {
        $logger = new \Psr\Log\Logger();
        Cache::get('key');
    }
}
PHP;

        $astResult = $this->astParser->parse($code);
        $tokenResult = $this->tokenParser->parse($code);

        $this->assertSame($astResult->getUses(), $tokenResult->getUses(), 'Uses should match');
        $this->assertSame($astResult->getExtends(), $tokenResult->getExtends(), 'Extends should match');
        $this->assertSame($astResult->getImplements(), $tokenResult->getImplements(), 'Implements should match');
        $this->assertSame($astResult->getDeclaredClasses(), $tokenResult->getDeclaredClasses(), 'Declared classes should match');
    }

    public function testMultipleUseStatements(): void
    {
        $code = <<<'PHP'
<?php

use Foo\Bar;
use Baz\Qux, Another\Class;
use Test\{ClassA, ClassB, ClassC};

class MyClass
{
}
PHP;

        $astResult = $this->astParser->parse($code);
        $tokenResult = $this->tokenParser->parse($code);

        $this->assertEqualsCanonicalizing($astResult->getUses(), $tokenResult->getUses(), 'Multiple use statements should match');
    }

    public function testTraitUsage(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

use App\Traits\Loggable;
use App\Traits\Cacheable;

class Service
{
    use Loggable, Cacheable;
}
PHP;

        $astResult = $this->astParser->parse($code);
        $tokenResult = $this->tokenParser->parse($code);

        $this->assertSame($astResult->getTraits(), $tokenResult->getTraits(), 'Trait usage should match');
    }

    public function testInterfaceDeclaration(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Contracts;

use Psr\Log\LoggerInterface;

interface UserInterface extends LoggerInterface
{
    public function getName(): string;
}
PHP;

        $astResult = $this->astParser->parse($code);
        $tokenResult = $this->tokenParser->parse($code);

        $this->assertSame($astResult->getDeclaredClasses(), $tokenResult->getDeclaredClasses(), 'Interface declaration should match');
        $this->assertSame($astResult->getExtends(), $tokenResult->getExtends(), 'Interface extends should match');
    }

    public function testMultipleImplements(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

interface A {}
interface B {}
interface C {}

class MyClass implements A, B, C
{
}
PHP;

        $astResult = $this->astParser->parse($code);
        $tokenResult = $this->tokenParser->parse($code);

        $this->assertEqualsCanonicalizing($astResult->getImplements(), $tokenResult->getImplements(), 'Multiple implements should match');
    }

    public function testNewInstantiation(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

use Monolog\Logger;

class Service
{
    public function create()
    {
        $logger = new Logger('app');
        $cache = new \Redis();
        $db = new \PDO('mysql:host=localhost');
    }
}
PHP;

        $astResult = $this->astParser->parse($code);
        $tokenResult = $this->tokenParser->parse($code);

        $this->assertEqualsCanonicalizing($astResult->getInstantiations(), $tokenResult->getInstantiations(), 'New instantiations should match');
    }

    public function testStaticCalls(): void
    {
        $code = <<<'PHP'
<?php

namespace App;

use Illuminate\Support\Facades\Cache;

class Service
{
    public function get()
    {
        Cache::get('key');
        \Redis::connect();
        DB::table('users')->get();
    }
}
PHP;

        $astResult = $this->astParser->parse($code);
        $tokenResult = $this->tokenParser->parse($code);

        $this->assertEqualsCanonicalizing($astResult->getStaticCalls(), $tokenResult->getStaticCalls(), 'Static calls should match');
    }

    public function testComplexNamespaceResolution(): void
    {
        $code = <<<'PHP'
<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository as Repo;

class UserService
{
    public function process()
    {
        $user = new User();
        $repo = new Repo();
        \App\Cache\Manager::clear();
    }
}
PHP;

        $astResult = $this->astParser->parse($code);
        $tokenResult = $this->tokenParser->parse($code);

        $this->assertEqualsCanonicalizing($astResult->getUses(), $tokenResult->getUses(), 'Aliased uses should match');
        $this->assertEqualsCanonicalizing($astResult->getInstantiations(), $tokenResult->getInstantiations(), 'Instantiations with aliases should match');
    }

    public function testEmptyFile(): void
    {
        $code = '<?php';

        $astResult = $this->astParser->parse($code);
        $tokenResult = $this->tokenParser->parse($code);

        $this->assertEmpty($astResult->getUses());
        $this->assertEmpty($tokenResult->getUses());
        $this->assertEmpty($astResult->getDeclaredClasses());
        $this->assertEmpty($tokenResult->getDeclaredClasses());
    }

    public function testMalformedPHP(): void
    {
        $code = '<?php class Foo { invalid syntax';

        $astResult = $this->astParser->parse($code);
        $tokenResult = $this->tokenParser->parse($code);

        // Both should handle parse errors gracefully
        $this->assertIsArray($astResult->getUses());
        $this->assertIsArray($tokenResult->getUses());
    }

    public function testRealWorldExample(): void
    {
        // Real-world example from the codebase
        $code = file_get_contents(__DIR__ . '/../../src/Analyzer/DependencyAnalyzer.php');

        $astResult = $this->astParser->parse($code);
        $tokenResult = $this->tokenParser->parse($code);

        $this->assertEqualsCanonicalizing($astResult->getUses(), $tokenResult->getUses(), 'Real-world uses should match');
        $this->assertEqualsCanonicalizing($astResult->getDeclaredClasses(), $tokenResult->getDeclaredClasses(), 'Real-world classes should match');
    }

    public function testPerformanceComparison(): void
    {
        // Generate a large PHP file for performance testing
        $code = "<?php\n\nnamespace App;\n\n";

        // Add 100 use statements
        for ($i = 0; $i < 100; $i++) {
            $code .= "use App\\Models\\Class{$i};\n";
        }

        $code .= "\nclass TestClass {\n";

        // Add 100 method calls
        for ($i = 0; $i < 100; $i++) {
            $code .= "    public function method{$i}() {\n";
            $code .= "        \$obj = new Class{$i}();\n";
            $code .= "        Class{$i}::staticMethod();\n";
            $code .= "    }\n";
        }

        $code .= "}\n";

        // Benchmark AST parser
        $startAst = microtime(true);
        $astResult = $this->astParser->parse($code);
        $astTime = microtime(true) - $startAst;

        // Benchmark token parser
        $startToken = microtime(true);
        $tokenResult = $this->tokenParser->parse($code);
        $tokenTime = microtime(true) - $startToken;

        // Token parser should be significantly faster
        $this->assertLessThan($astTime, $tokenTime, 'Token parser should be faster than AST parser');

        // But results should be the same
        $this->assertEqualsCanonicalizing($astResult->getUses(), $tokenResult->getUses());
        $this->assertEqualsCanonicalizing($astResult->getInstantiations(), $tokenResult->getInstantiations());

        // Output performance metrics
        $speedup = round($astTime / $tokenTime, 2);
        echo sprintf("\n\nPerformance: AST=%.4fs, Token=%.4fs, Speedup=%sx\n", $astTime, $tokenTime, $speedup);
    }
}
