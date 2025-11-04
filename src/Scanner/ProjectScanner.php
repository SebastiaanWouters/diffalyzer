<?php

declare(strict_types=1);

namespace Diffalyzer\Scanner;

use Symfony\Component\Finder\Finder;

final class ProjectScanner
{
    private ?array $cachedFiles = null;
    private ?GitignoreFilter $gitignoreFilter = null;

    public function __construct(
        private readonly string $projectRoot,
        private readonly bool $useCache = true,
        private readonly bool $respectGitignore = true
    ) {
    }

    public function getAllPhpFiles(): array
    {
        // Return cached result if available
        if ($this->useCache && $this->cachedFiles !== null) {
            return $this->cachedFiles;
        }

        $files = $this->scanPhpFiles();

        // Apply gitignore filtering
        if ($this->respectGitignore) {
            $files = $this->filterByGitignore($files);
        }

        // Cache the result
        if ($this->useCache) {
            $this->cachedFiles = $files;
        }

        return $files;
    }

    /**
     * Get only files that changed according to git
     * This is much faster than full filesystem scan for incremental analysis
     */
    public function getGitChangedFiles(): array
    {
        // Get files from git diff
        $process = new \Symfony\Component\Process\Process(
            ['git', 'diff', '--name-only', '--diff-filter=ACM', 'HEAD'],
            $this->projectRoot
        );
        $process->run();

        if (!$process->isSuccessful()) {
            // Fallback to full scan if git command fails
            return $this->getAllPhpFiles();
        }

        $output = $process->getOutput();
        $changedFiles = array_filter(
            explode("\n", $output),
            fn($file) => $file !== '' && str_ends_with($file, '.php')
        );

        // Also get untracked files
        $process = new \Symfony\Component\Process\Process(
            ['git', 'ls-files', '--others', '--exclude-standard', '*.php'],
            $this->projectRoot
        );
        $process->run();

        if ($process->isSuccessful()) {
            $untrackedFiles = array_filter(
                explode("\n", $process->getOutput()),
                fn($file) => $file !== '' && str_ends_with($file, '.php')
            );
            $changedFiles = array_merge($changedFiles, $untrackedFiles);
        }

        return array_values($changedFiles);
    }

    /**
     * Clear the file cache
     */
    public function clearCache(): void
    {
        $this->cachedFiles = null;
    }

    /**
     * Scan filesystem for all PHP files
     */
    private function scanPhpFiles(): array
    {
        $finder = new Finder();
        $finder
            ->files()
            ->in($this->projectRoot)
            ->name('*.php')
            ->exclude(['vendor', 'node_modules', '.git', 'cache', 'var', '.diffalyzer'])
            ->ignoreVCS(true)
            ->ignoreDotFiles(false);

        $files = [];
        foreach ($finder as $file) {
            $relativePath = str_replace($this->projectRoot . '/', '', $file->getRealPath());
            $files[] = $relativePath;
        }

        return $files;
    }

    /**
     * Filter files using .gitignore patterns
     */
    private function filterByGitignore(array $files): array
    {
        if ($this->gitignoreFilter === null) {
            $this->gitignoreFilter = new GitignoreFilter($this->projectRoot);
        }

        return $this->gitignoreFilter->filter($files);
    }

    /**
     * Check if a specific file should be scanned
     */
    public function shouldScanFile(string $relativePath): bool
    {
        // Check if it's a PHP file
        if (!str_ends_with($relativePath, '.php')) {
            return false;
        }

        // Check gitignore
        if ($this->respectGitignore) {
            if ($this->gitignoreFilter === null) {
                $this->gitignoreFilter = new GitignoreFilter($this->projectRoot);
            }

            if ($this->gitignoreFilter->shouldIgnore($relativePath)) {
                return false;
            }
        }

        // Check if in excluded directories
        $excludedPatterns = ['vendor/', 'node_modules/', '.git/', 'cache/', 'var/', '.diffalyzer/'];
        foreach ($excludedPatterns as $pattern) {
            if (str_starts_with($relativePath, $pattern)) {
                return false;
            }
        }

        return true;
    }
}
