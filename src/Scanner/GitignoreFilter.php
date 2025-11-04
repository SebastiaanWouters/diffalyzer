<?php

declare(strict_types=1);

namespace Diffalyzer\Scanner;

/**
 * Filters files based on .gitignore patterns
 */
class GitignoreFilter
{
    private array $patterns = [];
    private array $compiledPatterns = [];

    public function __construct(
        private readonly string $projectRoot
    ) {
        $this->loadGitignore();
    }

    /**
     * Load and parse .gitignore file(s)
     */
    private function loadGitignore(): void
    {
        $gitignorePath = $this->projectRoot . '/.gitignore';

        if (!file_exists($gitignorePath)) {
            return;
        }

        $content = file_get_contents($gitignorePath);
        if ($content === false) {
            return;
        }

        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $this->patterns[] = $line;
            $this->compiledPatterns[] = $this->compilePattern($line);
        }
    }

    /**
     * Check if a file should be ignored
     *
     * @param string $relativePath Path relative to project root
     * @return bool True if file should be ignored
     */
    public function shouldIgnore(string $relativePath): bool
    {
        // Normalize path separators
        $relativePath = str_replace('\\', '/', $relativePath);

        // Always ignore .git directory
        if (str_starts_with($relativePath, '.git/') || $relativePath === '.git') {
            return true;
        }

        // Check each pattern
        foreach ($this->compiledPatterns as $index => $pattern) {
            $originalPattern = $this->patterns[$index];

            // Handle negation patterns (!)
            $negation = str_starts_with($originalPattern, '!');
            if ($negation) {
                $originalPattern = substr($originalPattern, 1);
                $pattern = $this->compilePattern($originalPattern);
            }

            if ($this->matchesPattern($relativePath, $pattern, $originalPattern)) {
                // If it's a negation pattern, don't ignore
                if ($negation) {
                    return false;
                }
                // Otherwise, ignore
                return true;
            }
        }

        return false;
    }

    /**
     * Filter array of files, removing ignored ones
     */
    public function filter(array $files): array
    {
        return array_values(array_filter($files, fn($file) => !$this->shouldIgnore($file)));
    }

    /**
     * Convert gitignore pattern to regex
     */
    private function compilePattern(string $pattern): string
    {
        $pattern = trim($pattern);

        // Handle directory-only patterns (ending with /)
        $directoryOnly = str_ends_with($pattern, '/');
        if ($directoryOnly) {
            $pattern = rtrim($pattern, '/');
        }

        // Escape special regex characters except * and ?
        $pattern = preg_quote($pattern, '#');

        // Handle ** (match any directory depth)
        $pattern = str_replace('\*\*', '.*', $pattern);

        // Handle * (match any character except /)
        $pattern = str_replace('\*', '[^/]*', $pattern);

        // Handle ? (match any single character)
        $pattern = str_replace('\?', '.', $pattern);

        // If pattern starts with /, it's anchored to root
        if (str_starts_with($pattern, '\\/')) {
            $pattern = '^' . substr($pattern, 2);
        } else {
            // Otherwise, it can match at any level
            $pattern = '(?:^|/)' . $pattern;
        }

        if ($directoryOnly) {
            $pattern .= '(?:/|$)';
        } else {
            $pattern .= '(?:/|$)?';
        }

        return '#' . $pattern . '#';
    }

    /**
     * Check if path matches pattern
     */
    private function matchesPattern(string $path, string $regex, string $original): bool
    {
        // Suppress regex warnings for invalid patterns
        set_error_handler(function() { /* ignore */ });

        // Direct match
        $directMatch = @preg_match($regex, $path);
        if ($directMatch === 1) {
            restore_error_handler();
            return true;
        }

        // Also check if any parent directory matches (for directory patterns)
        $parts = explode('/', $path);
        for ($i = 1; $i < count($parts); $i++) {
            $parentPath = implode('/', array_slice($parts, 0, $i));
            $parentMatch = @preg_match($regex, $parentPath);
            if ($parentMatch === 1) {
                restore_error_handler();
                return true;
            }
        }

        restore_error_handler();
        return false;
    }

    /**
     * Check if gitignore file exists
     */
    public function exists(): bool
    {
        return file_exists($this->projectRoot . '/.gitignore');
    }

    /**
     * Get loaded patterns (for debugging)
     */
    public function getPatterns(): array
    {
        return $this->patterns;
    }
}
