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
        $this->formatter = new PhpUnitFormatter('/project/root', []);
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

    public function testCustomTestPatternMatchesSpecFiles(): void
    {
        $formatter = new PhpUnitFormatter('/project/root', [], '/Spec\.php$/');
        $files = ['spec/UserSpec.php', 'tests/UserTest.php', 'src/User.php'];
        $result = $formatter->format($files, false);

        $this->assertStringContainsString('spec/UserSpec.php', $result);
        // When using custom pattern, default patterns are ignored
        $this->assertStringNotContainsString('tests/UserTest.php', $result);
        $this->assertStringNotContainsString('src/User.php', $result);
    }

    public function testCustomTestPatternWithSpecDirectory(): void
    {
        $formatter = new PhpUnitFormatter('/project/root', [], '/^spec\//');
        $files = ['spec/UserSpec.php', 'spec/Unit/FooSpec.php', 'tests/UserTest.php', 'src/User.php'];
        $result = $formatter->format($files, false);

        $this->assertStringContainsString('spec/UserSpec.php', $result);
        $this->assertStringContainsString('spec/Unit/FooSpec.php', $result);
        // Default test patterns should not match with custom pattern
        $this->assertStringNotContainsString('tests/UserTest.php', $result);
        $this->assertStringNotContainsString('src/User.php', $result);
    }

    public function testCustomTestPatternWithMultipleOptions(): void
    {
        $formatter = new PhpUnitFormatter('/project/root', [], '/(Test\.php|Spec\.php)$/');
        $files = [
            'tests/UserTest.php',
            'spec/UserSpec.php',
            'tests/FooTest.php',
            'spec/BarSpec.php',
            'src/User.php'
        ];
        $result = $formatter->format($files, false);

        $this->assertStringContainsString('tests/UserTest.php', $result);
        $this->assertStringContainsString('spec/UserSpec.php', $result);
        $this->assertStringContainsString('tests/FooTest.php', $result);
        $this->assertStringContainsString('spec/BarSpec.php', $result);
        $this->assertStringNotContainsString('src/User.php', $result);
    }

    public function testCustomTestPatternOverridesDefaultBehavior(): void
    {
        // Pattern that only matches files ending with 'Integration.php'
        $formatter = new PhpUnitFormatter('/project/root', [], '/Integration\.php$/');
        $files = [
            'tests/UserTest.php',
            'tests/UserIntegration.php',
            'src/User.php'
        ];
        $result = $formatter->format($files, false);

        $this->assertStringContainsString('tests/UserIntegration.php', $result);
        // Even though UserTest.php would match default pattern, it shouldn't match custom pattern
        $this->assertStringNotContainsString('tests/UserTest.php', $result);
        $this->assertStringNotContainsString('src/User.php', $result);
    }

    public function testCustomTestPatternWithCaseInsensitiveMatch(): void
    {
        $formatter = new PhpUnitFormatter('/project/root', [], '/test\.php$/i');
        $files = [
            'tests/usertest.php',
            'tests/UserTEST.php',
            'tests/UserTest.php',
            'src/User.php'
        ];
        $result = $formatter->format($files, false);

        $this->assertStringContainsString('tests/usertest.php', $result);
        $this->assertStringContainsString('tests/UserTEST.php', $result);
        $this->assertStringContainsString('tests/UserTest.php', $result);
        $this->assertStringNotContainsString('src/User.php', $result);
    }

    public function testNullCustomPatternUsesDefaultBehavior(): void
    {
        $formatter = new PhpUnitFormatter('/project/root', [], null);
        $files = ['tests/UserTest.php', 'src/User.php'];
        $result = $formatter->format($files, false);

        $this->assertSame('tests/UserTest.php', $result);
    }

    public function testExcludesFixtureFiles(): void
    {
        $files = [
            'tests/UserTest.php',
            'tests/Fixtures/UserDataFixture.php',
            'tests/DataFixtures/LoadUserData.php',
            'tests/_support/Helper.php',
            'tests/fixtures/data.php',
            'src/User.php'
        ];
        $result = $this->formatter->format($files, false);

        $this->assertStringContainsString('tests/UserTest.php', $result);
        $this->assertStringNotContainsString('Fixtures', $result);
        $this->assertStringNotContainsString('DataFixtures', $result);
        $this->assertStringNotContainsString('_support', $result);
        $this->assertStringNotContainsString('fixtures', $result);
    }

    public function testExcludesHelperFiles(): void
    {
        $files = [
            'tests/UserTest.php',
            'tests/TestHelper.php',
            'tests/TestCase.php',
            'tests/AbstractTestCase.php',
            'tests/helpers/TestHelper.php',
            'src/User.php'
        ];
        $result = $this->formatter->format($files, false);

        $this->assertStringContainsString('tests/UserTest.php', $result);
        $this->assertStringNotContainsString('TestHelper.php', $result);
        $this->assertStringNotContainsString('TestCase.php', $result);
        $this->assertStringNotContainsString('AbstractTestCase.php', $result);
    }

    public function testExcludesBootstrapFiles(): void
    {
        $files = [
            'tests/UserTest.php',
            'tests/bootstrap.php',
            'tests/_bootstrap.php',
            'src/User.php'
        ];
        $result = $this->formatter->format($files, false);

        $this->assertStringContainsString('tests/UserTest.php', $result);
        $this->assertStringNotContainsString('bootstrap.php', $result);
        $this->assertStringNotContainsString('_bootstrap.php', $result);
    }

    public function testSingleFileWithMethod(): void
    {
        $files = ['tests/UserTest.php::testLogin'];
        $result = $this->formatter->format($files, false);

        $this->assertSame('tests/UserTest.php --filter testLogin', $result);
    }

    public function testMultipleFilesWithMethods(): void
    {
        $files = [
            'tests/UserTest.php::testLogin',
            'tests/FooTest.php::testBar'
        ];
        $result = $this->formatter->format($files, false);

        $this->assertSame('tests/UserTest.php tests/FooTest.php --filter \'/testLogin|testBar/\'', $result);
    }

    public function testSameFileWithMultipleMethods(): void
    {
        $files = [
            'tests/UserTest.php::testLogin',
            'tests/UserTest.php::testLogout'
        ];
        $result = $this->formatter->format($files, false);

        $this->assertSame('tests/UserTest.php --filter \'/testLogin|testLogout/\'', $result);
    }

    public function testMixedFilesWithAndWithoutMethods(): void
    {
        $files = [
            'tests/UserTest.php::testLogin',
            'tests/FooTest.php',
            'tests/BarTest.php'
        ];
        $result = $this->formatter->format($files, false);

        $this->assertSame('tests/UserTest.php tests/FooTest.php tests/BarTest.php --filter testLogin', $result);
    }

    public function testFileWithEmptyMethodName(): void
    {
        $files = ['tests/UserTest.php::'];
        $result = $this->formatter->format($files, false);

        $this->assertSame('tests/UserTest.php', $result);
    }

    public function testMethodNameWithSpecialRegexCharacters(): void
    {
        $files = ['tests/UserTest.php::testSomething[data-set]'];
        $result = $this->formatter->format($files, false);

        $this->assertSame('tests/UserTest.php --filter testSomething\[data\-set\]', $result);
    }

    public function testMultipleMethodsWithSpecialCharacters(): void
    {
        $files = [
            'tests/UserTest.php::test(foo)',
            'tests/UserTest.php::test[bar]'
        ];
        $result = $this->formatter->format($files, false);

        $this->assertSame('tests/UserTest.php --filter \'/test\(foo\)|test\[bar\]/\'', $result);
    }

    public function testMethodSyntaxWithNonTestFile(): void
    {
        $files = [
            'src/User.php::someMethod',
            'tests/UserTest.php::testLogin'
        ];
        $result = $this->formatter->format($files, false);

        $this->assertSame('tests/UserTest.php --filter testLogin', $result);
    }

    public function testFullScanIgnoresMethodSyntax(): void
    {
        $files = [
            'tests/UserTest.php::testLogin',
            'tests/FooTest.php::testBar'
        ];
        $result = $this->formatter->format($files, true);

        $this->assertSame('', $result);
    }

    public function testDeduplicationOfSameFileWithDifferentMethods(): void
    {
        $files = [
            'tests/UserTest.php::testLogin',
            'tests/UserTest.php::testLogout',
            'tests/UserTest.php::testRegister'
        ];
        $result = $this->formatter->format($files, false);

        $fileCount = substr_count($result, 'tests/UserTest.php');
        $this->assertSame(1, $fileCount);
        $this->assertStringContainsString('--filter \'/testLogin|testLogout|testRegister/\'', $result);
    }

    public function testMethodSyntaxWithCustomTestPattern(): void
    {
        $formatter = new PhpUnitFormatter('/project/root', [], '/Spec\.php$/');
        $files = [
            'spec/UserSpec.php::testUserCreation',
            'tests/UserTest.php::testLogin'
        ];
        $result = $formatter->format($files, false);

        $this->assertSame('spec/UserSpec.php --filter testUserCreation', $result);
        $this->assertStringNotContainsString('tests/UserTest.php', $result);
    }

    public function testFormatMethodsWithSingleTestMethod(): void
    {
        $methods = ['App\\Tests\\UserTest::testLogin'];
        $result = $this->formatter->formatMethods($methods, false);

        $this->assertSame('--filter UserTest', $result);
    }

    public function testFormatMethodsWithMultipleTestMethods(): void
    {
        $methods = [
            'App\\Tests\\UserTest::testLogin',
            'App\\Tests\\AccountTest::testCreate'
        ];
        $result = $this->formatter->formatMethods($methods, false);

        $this->assertSame('--filter \'/UserTest|AccountTest/\'', $result);
    }

    public function testFormatMethodsFiltersOutNonTestClasses(): void
    {
        $methods = [
            'App\\Service\\UserService::getUserById',
            'App\\Tests\\UserTest::testLogin',
            'App\\Repository\\UserRepository::findAll',
            'assertSame',
            'service'
        ];
        $result = $this->formatter->formatMethods($methods, false);

        $this->assertSame('--filter UserTest', $result);
    }

    public function testFormatMethodsWithFullScan(): void
    {
        $methods = ['App\\Tests\\UserTest::testLogin'];
        $result = $this->formatter->formatMethods($methods, true);

        $this->assertSame('', $result);
    }

    public function testFormatMethodsWithEmptyArray(): void
    {
        $result = $this->formatter->formatMethods([], false);

        $this->assertSame('', $result);
    }

    public function testFormatMethodsIgnoresClassesNotEndingWithTest(): void
    {
        $methods = [
            'App\\Service\\TestHelper::doSomething',
            'App\\Tests\\UserTest::testLogin',
            'App\\Model\\InternalTestData::getData'
        ];
        $result = $this->formatter->formatMethods($methods, false);

        $this->assertSame('--filter UserTest', $result);
    }
}
