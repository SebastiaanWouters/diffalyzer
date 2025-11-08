<?php

declare(strict_types=1);

namespace Diffalyzer\Matcher;

final class FullScanMatcher
{
    private const BUILT_IN_PATTERNS = [
        'composer.json',
        'composer.lock',
    ];

    /** @var array<string> */
    private array $patterns;

    /** @var array{file: string, pattern: string}|null */
    private ?array $lastMatch = null;

    /**
     * @param array<string>|null $configPatterns Patterns from config file, or null to use built-in defaults
     */
    public function __construct(?array $configPatterns = null)
    {
        // Use config patterns if explicitly provided (even if empty), otherwise use built-in defaults
        $this->patterns = $configPatterns !== null ? $configPatterns : self::BUILT_IN_PATTERNS;
    }

    /**
     * Check if any changed file should trigger a full scan
     *
     * @param array<string> $changedFiles
     * @param string|null $cliPattern CLI-provided pattern (overrides config/built-in)
     * @return bool
     */
    public function shouldTriggerFullScan(array $changedFiles, ?string $cliPattern = null): bool
    {
        $this->lastMatch = null;

        // Complete override: CLI pattern takes precedence
        $patternsToUse = $this->patterns;
        if ($cliPattern !== null && $cliPattern !== '') {
            $patternsToUse = [$cliPattern];
        }

        foreach ($changedFiles as $file) {
            foreach ($patternsToUse as $pattern) {
                if ($this->matchesPattern($file, $pattern)) {
                    $this->lastMatch = ['file' => $file, 'pattern' => $pattern];
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get the file and pattern that triggered the last full scan
     *
     * @return array{file: string, pattern: string}|null
     */
    public function getLastMatch(): ?array
    {
        return $this->lastMatch;
    }

    /**
     * Match a file against a pattern (glob or regex)
     *
     * @param string $file File path to check
     * @param string $pattern Glob pattern (e.g., "*.json") or regex pattern (e.g., "/\.json$/")
     * @return bool
     */
    private function matchesPattern(string $file, string $pattern): bool
    {
        // Normalize path separators
        $file = str_replace('\\', '/', $file);

        // Check if pattern is a regex (starts with / or #)
        if ($this->isRegexPattern($pattern)) {
            return $this->matchesRegex($file, $pattern);
        }

        // Otherwise, treat as glob pattern
        return $this->matchesGlob($file, $pattern);
    }

    /**
     * Check if pattern is a regex pattern
     */
    private function isRegexPattern(string $pattern): bool
    {
        return str_starts_with($pattern, '/') || str_starts_with($pattern, '#');
    }

    /**
     * Match file against regex pattern
     */
    private function matchesRegex(string $file, string $pattern): bool
    {
        // Suppress warnings for invalid regex
        set_error_handler(function() { /* ignore */ });
        $result = @preg_match($pattern, $file);
        restore_error_handler();

        return $result === 1;
    }

    /**
     * Match file against glob pattern
     * Supports: *, **, ?, exact matches
     */
    private function matchesGlob(string $file, string $pattern): bool
    {
        // Normalize pattern separators
        $pattern = str_replace('\\', '/', $pattern);

        // Exact match (most common case for simple filenames)
        if ($file === $pattern || str_ends_with($file, '/' . $pattern)) {
            return true;
        }

        // Convert glob pattern to regex
        $regex = $this->globToRegex($pattern);
        return $this->matchesRegex($file, $regex);
    }

    /**
     * Convert glob pattern to regex
     */
    private function globToRegex(string $pattern): string
    {
        // Escape special regex characters except *, ?, and /
        $pattern = preg_quote($pattern, '#');

        // Handle ** (match any directory depth)
        $pattern = str_replace('\*\*', '.*', $pattern);

        // Handle * (match any character except /)
        $pattern = str_replace('\*', '[^/]*', $pattern);

        // Handle ? (match any single character)
        $pattern = str_replace('\?', '.', $pattern);

        // If pattern starts with /, it's anchored to root
        if (str_starts_with($pattern, '\\/')) {
            $pattern = '^' . substr($pattern, 2) . '$';
        } else {
            // Otherwise, it can match at any level or at the end
            $pattern = '(?:^|/)' . $pattern . '$';
        }

        return '#' . $pattern . '#';
    }

    /**
     * Get the currently configured patterns
     *
     * @return array<string>
     */
    public function getPatterns(): array
    {
        return $this->patterns;
    }
}
