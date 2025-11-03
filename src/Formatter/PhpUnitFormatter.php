<?php

declare(strict_types=1);

namespace Diffalyzer\Formatter;

final class PhpUnitFormatter implements FormatterInterface
{
    private const TEST_DIRECTORIES = ['tests', 'test', 'Tests', 'Test'];

    public function __construct(
        private readonly string $projectRoot
    ) {
    }

    public function format(array $files, bool $fullScan): string
    {
        if ($fullScan) {
            return '';
        }

        $testFiles = [];

        foreach ($files as $file) {
            if ($this->isTestFile($file)) {
                $testFiles[] = $file;
            } else {
                $testFile = $this->mapToTestFile($file);
                if ($testFile !== null && file_exists($this->projectRoot . '/' . $testFile)) {
                    $testFiles[] = $testFile;
                }
            }
        }

        return implode(' ', array_unique($testFiles));
    }

    private function isTestFile(string $file): bool
    {
        return str_contains($file, 'Test.php') ||
               str_starts_with($file, 'tests/') ||
               str_starts_with($file, 'test/');
    }

    private function mapToTestFile(string $file): ?string
    {
        foreach (self::TEST_DIRECTORIES as $testDir) {
            $testFile = preg_replace(
                '/^src\/(.+)\.php$/',
                $testDir . '/$1Test.php',
                $file
            );

            if ($testFile !== $file) {
                return $testFile;
            }
        }

        return null;
    }
}
