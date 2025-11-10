<?php

declare(strict_types=1);

namespace Diffalyzer\Git;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class ChangeDetector
{
    private readonly MethodChangeParser $methodParser;

    public function __construct(
        private readonly string $projectRoot
    ) {
        $this->methodParser = new MethodChangeParser();
    }

    public function getChangedFiles(
        ?string $from = null,
        ?string $to = null,
        bool $staged = false
    ): array {
        $allFiles = $this->getAllChangedFiles($from, $to, $staged);
        return array_filter($allFiles, fn(string $file): bool => str_ends_with($file, '.php'));
    }

    public function getAllChangedFiles(
        ?string $from = null,
        ?string $to = null,
        bool $staged = false
    ): array {
        $command = $this->buildGitCommand($from, $to, $staged);
        $process = new Process($command, $this->projectRoot);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            return [];
        }

        return explode("\n", $output);
    }

    /**
     * Get changed methods (method-level granularity)
     *
     * @return array<string, array<string>> File path => array of method names
     */
    public function getChangedMethods(
        ?string $from = null,
        ?string $to = null,
        bool $staged = false
    ): array {
        $command = $this->buildGitDiffCommand($from, $to, $staged);
        $process = new Process($command, $this->projectRoot);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $output = $process->getOutput();
        if (trim($output) === '') {
            return [];
        }

        return $this->methodParser->parse($output);
    }

    private function buildGitCommand(?string $from, ?string $to, bool $staged): array
    {
        if ($staged) {
            return ['git', 'diff', '--cached', '--name-only', '--diff-filter=ACMR'];
        }

        if ($from !== null && $to !== null) {
            return ['git', 'diff', "$from...$to", '--name-only', '--diff-filter=ACMR'];
        }

        if ($from !== null) {
            return ['git', 'diff', $from, '--name-only', '--diff-filter=ACMR'];
        }

        return ['git', 'diff', 'HEAD', '--name-only', '--diff-filter=ACMR'];
    }

    /**
     * Build git diff command with line-level context (for method detection)
     */
    private function buildGitDiffCommand(?string $from, ?string $to, bool $staged): array
    {
        // -U0 means 0 lines of context, only show changed lines
        // This makes parsing faster and more precise
        $baseCommand = ['git', 'diff', '-U0', '--diff-filter=ACMR'];

        if ($staged) {
            array_splice($baseCommand, 2, 0, ['--cached']);
            return $baseCommand;
        }

        if ($from !== null && $to !== null) {
            $baseCommand[] = "$from...$to";
            return $baseCommand;
        }

        if ($from !== null) {
            $baseCommand[] = $from;
            return $baseCommand;
        }

        $baseCommand[] = 'HEAD';
        return $baseCommand;
    }
}
