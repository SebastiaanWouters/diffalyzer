<?php

declare(strict_types=1);

namespace Diffalyzer\Formatter;

final class PhpUnitFormatter implements FormatterInterface
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly ?string $testPattern = null
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
        if ($this->testPattern !== null) {
            return preg_match($this->testPattern, $file) === 1;
        }

        return str_contains($file, 'Test.php') ||
               str_starts_with($file, 'tests/') ||
               str_starts_with($file, 'test/') ||
               str_starts_with($file, 'Tests/') ||
               str_starts_with($file, 'Test/');
    }
}
