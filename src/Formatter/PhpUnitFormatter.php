<?php

declare(strict_types=1);

namespace Diffalyzer\Formatter;

final class PhpUnitFormatter implements FormatterInterface
{
    public function __construct(
        private readonly string $projectRoot
    ) {
    }

    public function format(array $files, bool $fullScan): string
    {
        if ($fullScan) {
            return '';
        }

        $testFiles = array_filter($files, fn(string $file): bool => $this->isTestFile($file));

        return implode(' ', $testFiles);
    }

    private function isTestFile(string $file): bool
    {
        return str_contains($file, 'Test.php') ||
               str_starts_with($file, 'tests/') ||
               str_starts_with($file, 'test/') ||
               str_starts_with($file, 'Tests/') ||
               str_starts_with($file, 'Test/');
    }
}
