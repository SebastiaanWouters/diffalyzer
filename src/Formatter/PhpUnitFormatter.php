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

        return $this->buildPhpUnitCommand($testFiles);
    }

    private function buildPhpUnitCommand(array $testFiles): string
    {
        if (empty($testFiles)) {
            return '';
        }

        $filePaths = [];
        $methods = [];

        foreach ($testFiles as $file) {
            if (str_contains($file, '::')) {
                [$filePath, $methodName] = explode('::', $file, 2);

                if (!empty($methodName)) {
                    $filePaths[$filePath] = true;
                    $methods[] = preg_quote($methodName, '/');
                } else {
                    $filePaths[$filePath] = true;
                }
            } else {
                $filePaths[$file] = true;
            }
        }

        $output = implode(' ', array_keys($filePaths));

        if (!empty($methods)) {
            if (count($methods) === 1) {
                $output .= ' --filter ' . reset($methods);
            } else {
                $output .= ' --filter \'/' . implode('|', $methods) . '/\'';
            }
        }

        return $output;
    }

    public function formatMethods(array $methods, bool $fullScan): string
    {
        if ($fullScan) {
            return '';
        }

        $fileMethodPairs = [];
        foreach ($methods as $method) {
            $pair = $this->methodToFileMethod($method);
            if ($pair !== null) {
                $fileMethodPairs[] = $pair;
            }
        }

        if (empty($fileMethodPairs)) {
            return '';
        }

        return $this->buildPhpUnitCommand($fileMethodPairs);
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
        $filePath = str_contains($file, '::') ? explode('::', $file, 2)[0] : $file;

        if ($this->testPattern !== null) {
            return preg_match($this->testPattern, $filePath) === 1;
        }

        $excludePatterns = [
            '/[Ff]ixtures?/',
            '/_support/',
            '/bootstrap\.php$/',
            '/_bootstrap\.php$/',
            '/TestCase\.php$/',
            '/AbstractTest/',
            '/TestHelper\.php$/',
            '/helpers?/',
        ];

        foreach ($excludePatterns as $pattern) {
            if (preg_match($pattern, $filePath) === 1) {
                return false;
            }
        }

        return str_contains($filePath, 'Test.php') ||
               str_starts_with($filePath, 'tests/') ||
               str_starts_with($filePath, 'test/') ||
               str_starts_with($filePath, 'Tests/') ||
               str_starts_with($filePath, 'Test/');
    }
}
