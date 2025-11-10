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
