<?php

declare(strict_types=1);

namespace Diffalyzer\Git;

use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class ChangeDetector
{
    public function __construct(
        private readonly string $projectRoot
    ) {
    }

    public function getChangedFiles(
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

        $files = explode("\n", $output);

        return array_filter($files, fn(string $file): bool => str_ends_with($file, '.php'));
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
}
