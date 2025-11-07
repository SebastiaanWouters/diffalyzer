<?php

declare(strict_types=1);

namespace Diffalyzer\Cache;

/**
 * Manages persistent cache storage for dependency graphs
 */
class CacheManager
{
    private const CACHE_VERSION = '1.0.0';
    private const CACHE_DIR = '.diffalyzer/cache';
    private const GRAPH_CACHE_FILE = 'dependency-graph.json';
    private const REGISTRY_FILE = 'file-registry.json';

    private string $cacheDir;
    private string $graphCacheFile;
    private string $registryFile;

    public function __construct(
        private readonly string $projectRoot,
        private readonly FileHashRegistry $registry
    ) {
        $this->cacheDir = $projectRoot . '/' . self::CACHE_DIR;
        $this->graphCacheFile = $this->cacheDir . '/' . self::GRAPH_CACHE_FILE;
        $this->registryFile = $this->cacheDir . '/' . self::REGISTRY_FILE;
    }

    /**
     * Initialize cache directory
     */
    public function initialize(): bool
    {
        if (!is_dir($this->cacheDir)) {
            return mkdir($this->cacheDir, 0755, true);
        }
        return true;
    }

    /**
     * Check if cache exists and is valid
     */
    public function exists(): bool
    {
        return file_exists($this->graphCacheFile) && file_exists($this->registryFile);
    }

    /**
     * Load cached dependency graph
     */
    public function loadGraph(): ?array
    {
        if (!file_exists($this->graphCacheFile)) {
            return null;
        }

        $data = file_get_contents($this->graphCacheFile);
        if ($data === false) {
            return null;
        }

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return null;
        }

        // Validate cache version
        if (!isset($decoded['version']) || $decoded['version'] !== self::CACHE_VERSION) {
            return null;
        }

        return $decoded['data'] ?? null;
    }

    /**
     * Save dependency graph to cache
     */
    public function saveGraph(array $graphData): bool
    {
        if (!$this->initialize()) {
            return false;
        }

        $data = [
            'version' => self::CACHE_VERSION,
            'timestamp' => time(),
            'data' => $graphData,
        ];

        $json = json_encode($data);
        if ($json === false) {
            return false;
        }

        $result = file_put_contents($this->graphCacheFile, $json);
        return $result !== false;
    }

    /**
     * Load file registry
     */
    public function loadRegistry(): bool
    {
        return $this->registry->load($this->registryFile);
    }

    /**
     * Save file registry
     */
    public function saveRegistry(): bool
    {
        return $this->registry->save($this->registryFile);
    }

    /**
     * Clear all cache files
     */
    public function clear(): bool
    {
        $success = true;

        if (file_exists($this->graphCacheFile)) {
            $success = unlink($this->graphCacheFile) && $success;
        }

        if (file_exists($this->registryFile)) {
            $success = unlink($this->registryFile) && $success;
        }

        $this->registry->clear();

        return $success;
    }

    /**
     * Get cache statistics
     */
    public function getStats(): array
    {
        $stats = [
            'exists' => $this->exists(),
            'cache_dir' => $this->cacheDir,
            'tracked_files' => $this->registry->count(),
        ];

        if (file_exists($this->graphCacheFile)) {
            $data = json_decode(file_get_contents($this->graphCacheFile), true);
            $stats['cache_timestamp'] = $data['timestamp'] ?? null;
            $stats['cache_age_seconds'] = $data['timestamp'] ? (time() - $data['timestamp']) : null;
            $stats['cache_size_bytes'] = filesize($this->graphCacheFile);
        }

        return $stats;
    }

    /**
     * Get cache directory path
     */
    public function getCacheDir(): string
    {
        return $this->cacheDir;
    }
}
