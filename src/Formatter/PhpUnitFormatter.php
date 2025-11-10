<?php

declare(strict_types=1);

namespace Diffalyzer\Formatter;

final class PhpUnitFormatter implements MethodAwareFormatterInterface
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly array $classToFileMap = [],
        private readonly ?string $testPattern = null
    ) {
    }

    public function format(array $files, bool $fullScan): string
    {
        if ($fullScan) {
            return '';
        }

        // Filter to only test files
        $testFiles = array_filter($files, fn(string $file): bool => $this->isTestFile($file));

        return implode(' ', $testFiles);
    }

    public function formatMethods(array $methods, bool $fullScan): string
    {
        if ($fullScan) {
            return '';
        }

        $formatted = [];
        foreach ($methods as $method) {
            $formatted[] = $this->methodToFileMethod($method);
        }

        // Remove nulls (methods that couldn't be resolved)
        $formatted = array_filter($formatted);

        return implode(' ', $formatted);
    }

    /**
     * Convert fully qualified method name to file::method format
     *
     * @param string $fqMethodName e.g., "App\User::getName"
     * @return string|null e.g., "src/User.php::getName" or null if not resolvable
     */
    private function methodToFileMethod(string $fqMethodName): ?string
    {
        // Split class::method
        if (!str_contains($fqMethodName, '::')) {
            return null;
        }

        [$className, $methodName] = explode('::', $fqMethodName, 2);

        // Look up file for class
        if (!isset($this->classToFileMap[$className])) {
            return null;
        }

        $file = $this->classToFileMap[$className];

        return "{$file}::{$methodName}";
    }

    private function isTestFile(string $file): bool
    {
        if ($this->testPattern !== null) {
            return preg_match($this->testPattern, $file) === 1;
        }

        // Exclude common non-test files
        $excludePatterns = [
            '/[Ff]ixtures?/',      // Fixtures/fixtures/DataFixtures
            '/_support/',           // Codeception support files
            '/bootstrap\.php$/',    // Bootstrap files
            '/_bootstrap\.php$/',   // Alternative bootstrap files
            '/TestCase\.php$/',     // Base test case classes
            '/AbstractTest/',       // Abstract test classes
            '/TestHelper\.php$/',   // Test helper files
            '/helpers?/',           // Helper directories
        ];

        foreach ($excludePatterns as $pattern) {
            if (preg_match($pattern, $file) === 1) {
                return false;
            }
        }

        // Must match test patterns
        return str_contains($file, 'Test.php') ||
               str_starts_with($file, 'tests/') ||
               str_starts_with($file, 'test/') ||
               str_starts_with($file, 'Tests/') ||
               str_starts_with($file, 'Test/');
    }
}
