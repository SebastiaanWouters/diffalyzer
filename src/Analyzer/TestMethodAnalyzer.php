<?php

declare(strict_types=1);

namespace Diffalyzer\Analyzer;

use Diffalyzer\Parser\MethodCallExtractor;

/**
 * Analyzes test files to identify test methods and what they test
 */
final class TestMethodAnalyzer
{
    private MethodCallExtractor $methodExtractor;

    public function __construct()
    {
        $this->methodExtractor = new MethodCallExtractor();
    }

    /**
     * Check if a file is a test file
     */
    public function isTestFile(string $file): bool
    {
        return str_contains($file, 'Test.php') ||
               str_starts_with($file, 'tests/') ||
               str_starts_with($file, 'test/') ||
               str_starts_with($file, 'Tests/') ||
               str_starts_with($file, 'Test/');
    }

    /**
     * Extract test methods and the methods they call
     *
     * @return array{testMethods: array<string, array<string>>, testFiles: array<string, string>}
     *         testMethods: test method => [called methods]
     *         testFiles: test method => file path
     */
    public function analyzeTestFiles(array $testFiles, string $projectRoot): array
    {
        $testMethods = [];
        $testFileMap = [];

        foreach ($testFiles as $file) {
            $absolutePath = str_starts_with($file, '/')
                ? $file
                : $projectRoot . '/' . $file;

            if (!file_exists($absolutePath)) {
                continue;
            }

            $code = file_get_contents($absolutePath);
            if ($code === false) {
                continue;
            }

            // Extract all method calls from the file
            $methodCalls = $this->methodExtractor->extract($code);

            // Filter to only test methods
            foreach ($methodCalls as $method => $calls) {
                if ($this->isTestMethod($method)) {
                    $testMethods[$method] = $calls;
                    $testFileMap[$method] = $file;
                }
            }
        }

        return [
            'testMethods' => $testMethods,
            'testFiles' => $testFileMap,
        ];
    }

    /**
     * Check if a method name looks like a test method
     */
    private function isTestMethod(string $method): bool
    {
        // Format: ClassName::methodName or Namespace\ClassName::methodName
        if (!str_contains($method, '::')) {
            return false;
        }

        $parts = explode('::', $method);
        $methodName = end($parts);

        // PHPUnit conventions:
        // 1. Methods starting with 'test'
        // 2. Methods with @test annotation (we'll approximate by checking common test patterns)
        return str_starts_with($methodName, 'test') ||
               str_ends_with($methodName, 'Test');
    }

    /**
     * Find test methods that call affected methods
     *
     * @param array<string> $affectedMethods Fully qualified method names
     * @param array<string, array<string>> $testMethods Test method => [called methods]
     * @return array<string> Test method names that should be run
     */
    public function findTestMethodsForAffectedMethods(
        array $affectedMethods,
        array $testMethods
    ): array {
        $relevantTests = [];

        foreach ($testMethods as $testMethod => $calledMethods) {
            foreach ($calledMethods as $calledMethod) {
                if (in_array($calledMethod, $affectedMethods, true)) {
                    $relevantTests[] = $testMethod;
                    break; // This test is relevant, move to next test
                }
            }
        }

        return $relevantTests;
    }

    /**
     * Find test methods that call any method on the specified classes
     *
     * This is a broader match than findTestMethodsForAffectedMethods - useful when
     * a method changes but no tests directly call that specific method.
     *
     * @param array<string> $classes Fully qualified class names
     * @param array<string, array<string>> $testMethods Test method => [called methods]
     * @return array<string> Test method names that should be run
     */
    public function findTestMethodsForClasses(
        array $classes,
        array $testMethods
    ): array {
        $relevantTests = [];

        foreach ($testMethods as $testMethod => $calledMethods) {
            foreach ($calledMethods as $calledMethod) {
                // Check if the called method belongs to any of the changed classes
                if (str_contains($calledMethod, '::')) {
                    [$calledClass] = explode('::', $calledMethod, 2);
                    if (in_array($calledClass, $classes, true)) {
                        $relevantTests[] = $testMethod;
                        break; // This test is relevant, move to next test
                    }
                }
            }
        }

        return $relevantTests;
    }

    /**
     * Find test methods based on file path/namespace similarity
     *
     * This is a heuristic fallback: if src/Kuleuven/ModelBundle/Entity/Country.php changes,
     * we should run tests in tests/.../ModelBundle/.../
     *
     * @param array<string> $changedFiles Relative file paths that changed
     * @param array<string, array<string>> $testMethods Test method => [called methods]
     * @return array<string> Test method names that should be run
     */
    public function findTestMethodsByNamespace(array $changedFiles, array $testMethods): array
    {
        $relevantTests = [];

        foreach ($changedFiles as $changedFile) {
            // Extract meaningful path components (e.g., "ModelBundle", "Entity")
            // Skip common prefixes like "src/"
            $pathParts = array_filter(
                explode('/', $changedFile),
                fn($part) => !in_array($part, ['src', 'lib', 'app', 'tests'], true)
            );

            // Look for test methods whose file paths contain these components
            foreach ($testMethods as $testMethod => $calls) {
                // Get the test method's class name
                if (!str_contains($testMethod, '::')) {
                    continue;
                }

                [$testClass] = explode('::', $testMethod, 2);

                // Convert class name to path-like string for matching
                // E.g., Kuleuven\ModelBundle\Tests\EntityTest -> Kuleuven/ModelBundle/Tests/EntityTest
                $testPath = str_replace('\\', '/', $testClass);

                // Check if any significant path components match
                $matchCount = 0;
                foreach ($pathParts as $part) {
                    if (stripos($testPath, $part) !== false) {
                        $matchCount++;
                    }
                }

                // If we match at least 2 components (e.g., "ModelBundle" + "Entity"), include the test
                if ($matchCount >= 2) {
                    $relevantTests[] = $testMethod;
                }
            }
        }

        return array_unique($relevantTests);
    }

    /**
     * Map test methods back to test files
     *
     * @param array<string> $testMethods Test method names
     * @param array<string, string> $testFileMap Test method => file path
     * @return array<string> Unique file paths
     */
    public function mapTestMethodsToFiles(array $testMethods, array $testFileMap): array
    {
        $files = [];

        foreach ($testMethods as $testMethod) {
            if (isset($testFileMap[$testMethod])) {
                $files[$testFileMap[$testMethod]] = true;
            }
        }

        return array_keys($files);
    }
}
