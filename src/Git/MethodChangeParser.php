<?php

declare(strict_types=1);

namespace Diffalyzer\Git;

/**
 * Parses git diff output to identify which methods were changed
 */
final class MethodChangeParser
{
    /**
     * Parse git diff output and map changes to methods
     *
     * @param string $diffOutput Git diff output from 'git diff -U0'
     * @return array<string, array<string>> File path => method names array
     */
    public function parse(string $diffOutput): array
    {
        $changes = [];
        $currentFile = null;
        $changedRanges = [];

        $lines = explode("\n", $diffOutput);

        foreach ($lines as $line) {
            // Detect file header: diff --git a/file.php b/file.php
            if (str_starts_with($line, 'diff --git')) {
                // Process previous file if exists
                if ($currentFile !== null && !empty($changedRanges)) {
                    $methods = $this->extractMethodsFromRanges($currentFile, $changedRanges);
                    if (!empty($methods)) {
                        $changes[$currentFile] = $methods;
                    }
                }

                // Extract new file path
                $currentFile = $this->extractFilePath($line);
                $changedRanges = [];
                continue;
            }

            // Detect +++ b/file.php (more reliable for file path)
            if (str_starts_with($line, '+++ b/')) {
                $currentFile = substr($line, 6); // Remove '+++ b/'
                continue;
            }

            // Parse hunk header: @@ -10,5 +15,8 @@
            if (str_starts_with($line, '@@')) {
                $range = $this->parseHunkHeader($line);
                if ($range !== null) {
                    $changedRanges[] = $range;
                }
            }
        }

        // Process last file
        if ($currentFile !== null && !empty($changedRanges)) {
            $methods = $this->extractMethodsFromRanges($currentFile, $changedRanges);
            if (!empty($methods)) {
                $changes[$currentFile] = $methods;
            }
        }

        return $changes;
    }

    /**
     * Extract file path from diff header
     */
    private function extractFilePath(string $line): ?string
    {
        // Format: diff --git a/src/File.php b/src/File.php
        if (preg_match('#b/(.+)$#', $line, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Parse hunk header to extract changed line range
     *
     * @return array{start: int, count: int}|null
     */
    private function parseHunkHeader(string $line): ?array
    {
        // Format: @@ -10,5 +15,8 @@ optional context
        // We care about the "+" side (new file): +15,8 means starting at line 15, 8 lines changed
        if (preg_match('/@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $matches)) {
            $start = (int)$matches[1];
            $count = isset($matches[2]) ? (int)$matches[2] : 1;
            return ['start' => $start, 'count' => $count];
        }
        return null;
    }

    /**
     * Extract method names from changed line ranges
     *
     * @param array<array{start: int, count: int}> $ranges
     * @return array<string> Method names
     */
    private function extractMethodsFromRanges(string $filePath, array $ranges): array
    {
        if (!file_exists($filePath) || !str_ends_with($filePath, '.php')) {
            return [];
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            return [];
        }

        // Build method map: line number => method name
        $methodMap = $this->buildMethodMap($content);

        // Find which methods contain the changed lines
        $changedMethods = [];
        foreach ($ranges as $range) {
            $startLine = $range['start'];
            $endLine = $startLine + $range['count'] - 1;

            for ($line = $startLine; $line <= $endLine; $line++) {
                if (isset($methodMap[$line])) {
                    $methodName = $methodMap[$line];
                    $changedMethods[$methodName] = true;
                }
            }
        }

        return array_keys($changedMethods);
    }

    /**
     * Build a map of line numbers to method names
     *
     * @return array<int, string> Line number => method name
     */
    private function buildMethodMap(string $code): array
    {
        $map = [];
        $tokens = @token_get_all($code);

        if ($tokens === false) {
            return $map;
        }

        $currentClass = null;
        $currentMethod = null;
        $methodStartLine = null;
        $braceDepth = 0;
        $inMethod = false;

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                // Track brace depth for method scope
                if ($token === '{') {
                    $braceDepth++;
                    if ($inMethod && $braceDepth === 1) {
                        // Method body starts
                        $methodStartLine = $token[2] ?? null;
                    }
                } elseif ($token === '}') {
                    $braceDepth--;
                    if ($inMethod && $braceDepth === 0) {
                        // Method ends
                        $inMethod = false;
                        $currentMethod = null;
                        $methodStartLine = null;
                    }
                }
                continue;
            }

            [$type, $text, $line] = $token;

            // Track current class
            if ($type === T_CLASS || $type === T_INTERFACE || $type === T_TRAIT) {
                $currentClass = $this->extractNextIdentifier($tokens, $i);
            }

            // Track methods
            if ($type === T_FUNCTION) {
                $methodName = $this->extractNextIdentifier($tokens, $i);
                if ($methodName !== null) {
                    $currentMethod = $currentClass !== null
                        ? "{$currentClass}::{$methodName}"
                        : $methodName;
                    $inMethod = true;
                    $braceDepth = 0;
                    $methodStartLine = $line;
                }
            }

            // Map lines to current method
            if ($inMethod && $currentMethod !== null) {
                $map[$line] = $currentMethod;
            }
        }

        return $map;
    }

    /**
     * Extract the next T_STRING token (identifier) after current position
     */
    private function extractNextIdentifier(array $tokens, int $position): ?string
    {
        $count = count($tokens);
        for ($i = $position + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_STRING) {
                return $token[1];
            }

            // Stop at certain tokens that indicate we've gone too far
            if (in_array($token[0], [T_EXTENDS, T_IMPLEMENTS, T_USE, T_FUNCTION, T_CLASS], true)) {
                break;
            }
        }

        return null;
    }
}
