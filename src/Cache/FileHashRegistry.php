<?php

declare(strict_types=1);

namespace Diffalyzer\Cache;

/**
 * Tracks file states (modification time, size, hash) to detect changes
 */
class FileHashRegistry
{
    private array $registry = [];

    public function __construct(
        private readonly string $projectRoot
    ) {
    }

    /**
     * Load registry from cache file
     */
    public function load(string $cacheFile): bool
    {
        if (!file_exists($cacheFile)) {
            return false;
        }

        $data = file_get_contents($cacheFile);
        if ($data === false) {
            return false;
        }

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return false;
        }

        $this->registry = $decoded;
        return true;
    }

    /**
     * Save registry to cache file
     */
    public function save(string $cacheFile): bool
    {
        $dir = dirname($cacheFile);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            return false;
        }

        $json = json_encode($this->registry, JSON_PRETTY_PRINT);
        return file_put_contents($cacheFile, $json) !== false;
    }

    /**
     * Update registry with current file state
     */
    public function updateFile(string $relativePath): void
    {
        $absolutePath = $this->getAbsolutePath($relativePath);

        if (!file_exists($absolutePath)) {
            unset($this->registry[$relativePath]);
            return;
        }

        $stat = stat($absolutePath);
        if ($stat === false) {
            return;
        }

        $this->registry[$relativePath] = [
            'mtime' => $stat['mtime'],
            'size' => $stat['size'],
            'hash' => $this->computeFileHash($absolutePath),
        ];
    }

    /**
     * Check if file has changed since last registry update
     */
    public function hasChanged(string $relativePath): bool
    {
        $absolutePath = $this->getAbsolutePath($relativePath);

        if (!file_exists($absolutePath)) {
            // File was deleted
            return isset($this->registry[$relativePath]);
        }

        if (!isset($this->registry[$relativePath])) {
            // New file
            return true;
        }

        $stat = stat($absolutePath);
        if ($stat === false) {
            return true;
        }

        $cached = $this->registry[$relativePath];

        // Quick check: mtime and size
        if ($cached['mtime'] !== $stat['mtime'] || $cached['size'] !== $stat['size']) {
            return true;
        }

        // If mtime/size match, assume unchanged (avoids hash computation)
        return false;
    }

    /**
     * Get all files that have changed
     */
    public function getChangedFiles(array $currentFiles): array
    {
        $changed = [];

        // Check existing files
        foreach ($currentFiles as $file) {
            if ($this->hasChanged($file)) {
                $changed[] = $file;
            }
        }

        // Check for deleted files
        foreach (array_keys($this->registry) as $cachedFile) {
            if (!in_array($cachedFile, $currentFiles, true)) {
                $changed[] = $cachedFile;
            }
        }

        return $changed;
    }

    /**
     * Update registry for multiple files
     */
    public function updateFiles(array $files): void
    {
        foreach ($files as $file) {
            $this->updateFile($file);
        }
    }

    /**
     * Clear the entire registry
     */
    public function clear(): void
    {
        $this->registry = [];
    }

    /**
     * Check if file exists in registry
     */
    public function has(string $relativePath): bool
    {
        return isset($this->registry[$relativePath]);
    }

    /**
     * Get file info from registry
     */
    public function get(string $relativePath): ?array
    {
        return $this->registry[$relativePath] ?? null;
    }

    /**
     * Get count of tracked files
     */
    public function count(): int
    {
        return count($this->registry);
    }

    private function getAbsolutePath(string $relativePath): string
    {
        return $this->projectRoot . '/' . $relativePath;
    }

    private function computeFileHash(string $absolutePath): string
    {
        // Use xxHash if available, otherwise md5 (fast enough for cache validation)
        if (function_exists('xxh64')) {
            return xxh64(file_get_contents($absolutePath));
        }

        return md5_file($absolutePath);
    }
}
