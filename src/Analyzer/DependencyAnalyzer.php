<?php

declare(strict_types=1);

namespace Diffalyzer\Analyzer;

use Diffalyzer\Cache\CacheInvalidator;
use Diffalyzer\Cache\CacheManager;
use Diffalyzer\Cache\FileHashRegistry;
use Diffalyzer\Strategy\StrategyInterface;
use Diffalyzer\Visitor\DependencyVisitor;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\ParserFactory;

final class DependencyAnalyzer
{
    private array $dependencyGraph = [];
    private array $reverseDependencyGraph = [];
    private array $classToFileMap = [];
    private ?CacheManager $cacheManager = null;
    private ?FileHashRegistry $registry = null;
    private ?CacheInvalidator $invalidator = null;
    private bool $cacheEnabled = true;
    private int $filesParsed = 0;
    private int $filesFromCache = 0;

    public function __construct(
        private readonly string $projectRoot,
        private readonly StrategyInterface $strategy
    ) {
    }

    /**
     * Enable or disable caching
     */
    public function setCacheEnabled(bool $enabled): void
    {
        $this->cacheEnabled = $enabled;
    }

    /**
     * Initialize cache system
     */
    private function initializeCache(): void
    {
        if (!$this->cacheEnabled) {
            return;
        }

        if ($this->cacheManager === null) {
            $this->registry = new FileHashRegistry($this->projectRoot);
            $this->cacheManager = new CacheManager($this->projectRoot, $this->registry);
            $this->invalidator = new CacheInvalidator();
            $this->cacheManager->initialize();
        }
    }

    public function buildDependencyGraph(array $phpFiles): void
    {
        $this->initializeCache();

        // Try to load from cache
        if ($this->cacheEnabled && $this->loadFromCache($phpFiles)) {
            return;
        }

        // Full rebuild
        $this->parseFiles($phpFiles);
        $this->buildReverseDependencyGraph();

        // Save to cache
        if ($this->cacheEnabled) {
            $this->saveToCache($phpFiles);
        }
    }

    /**
     * Load dependency graph from cache and perform incremental updates
     */
    private function loadFromCache(array $phpFiles): bool
    {
        // Load registry
        if (!$this->cacheManager->loadRegistry()) {
            return false;
        }

        // Load cached graph
        $cachedData = $this->cacheManager->loadGraph();
        if ($cachedData === null) {
            return false;
        }

        // Restore cached data
        $this->dependencyGraph = $cachedData['dependencyGraph'] ?? [];
        $this->classToFileMap = $cachedData['classToFileMap'] ?? [];
        $this->reverseDependencyGraph = $cachedData['reverseDependencyGraph'] ?? [];

        // Detect changed files
        $changedFiles = $this->registry->getChangedFiles($phpFiles);

        if (empty($changedFiles)) {
            // Perfect cache hit - no files changed
            $this->filesFromCache = count($phpFiles);
            return true;
        }

        // Clean up deleted files
        $cleaned = $this->invalidator->removeDeletedFiles(
            $phpFiles,
            $this->dependencyGraph,
            $this->classToFileMap
        );
        $this->dependencyGraph = $cleaned['dependencyGraph'];
        $this->classToFileMap = $cleaned['classToFileMap'];

        // Incremental update: parse only changed files
        $this->parseFiles($changedFiles, true);

        // Rebuild reverse graph (it's fast enough to do fully)
        $this->reverseDependencyGraph = []; // Clear before rebuild
        $this->buildReverseDependencyGraph();

        // Update statistics
        $this->filesParsed = count($changedFiles);
        $this->filesFromCache = count($phpFiles) - count($changedFiles);

        // Save updated cache
        $this->saveToCache($phpFiles);

        return true;
    }

    /**
     * Parse files and update dependency graph
     */
    private function parseFiles(array $phpFiles, bool $isIncremental = false): void
    {
        // Use parallel parsing for large file sets (not incremental updates)
        // Threshold: minimum 100 files to justify parallel overhead
        // This also helps avoid issues in test environments without proper vendor/ directories
        if (!$isIncremental && count($phpFiles) >= 100 && $this->shouldUseParallelParsing()) {
            $this->parseFilesParallel($phpFiles);
            return;
        }

        // Sequential parsing for small sets or incremental updates
        $this->parseFilesSequential($phpFiles, $isIncremental);
    }

    /**
     * Check if parallel parsing should be used
     */
    private function shouldUseParallelParsing(): bool
    {
        // Check if vendor/autoload.php exists (required for parallel workers)
        return file_exists($this->projectRoot . '/vendor/autoload.php');
    }

    /**
     * Parse files in parallel using multiple processes
     */
    private function parseFilesParallel(array $phpFiles): void
    {
        $parallelParser = new ParallelParser($this->projectRoot, $this->strategy);
        $results = $parallelParser->parseFiles($phpFiles);

        if (empty($results)) {
            // Parallel parsing failed, fall back to sequential
            $this->parseFilesSequential($phpFiles, false);
            return;
        }

        // Merge results into existing graphs
        $this->dependencyGraph = array_merge(
            $this->dependencyGraph,
            $results['dependencyGraph'] ?? []
        );
        $this->classToFileMap = array_merge(
            $this->classToFileMap,
            $results['classToFileMap'] ?? []
        );

        $this->filesParsed += count($phpFiles);
    }

    /**
     * Parse files sequentially (original implementation)
     */
    private function parseFilesSequential(array $phpFiles, bool $isIncremental): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $traverser = new NodeTraverser();

        foreach ($phpFiles as $file) {
            $absolutePath = $this->getAbsolutePath($file);
            if (!file_exists($absolutePath)) {
                continue;
            }

            try {
                $code = file_get_contents($absolutePath);
                if ($code === false) {
                    continue;
                }

                $ast = $parser->parse($code);
                if ($ast === null) {
                    continue;
                }

                $visitor = new DependencyVisitor();
                $traverser->addVisitor($visitor);
                $traverser->traverse($ast);
                $traverser->removeVisitor($visitor);

                // Remove old class mappings for this file (in incremental mode)
                if ($isIncremental) {
                    foreach ($this->classToFileMap as $class => $mappedFile) {
                        if ($mappedFile === $file) {
                            unset($this->classToFileMap[$class]);
                        }
                    }
                }

                // Add new class mappings
                foreach ($visitor->getDeclaredClasses() as $className) {
                    $this->classToFileMap[$className] = $file;
                }

                $dependencies = $this->strategy->extractDependencies($visitor);
                $this->dependencyGraph[$file] = $dependencies;

                $this->filesParsed++;
            } catch (Error $error) {
                continue;
            }
        }
    }

    /**
     * Save current state to cache
     */
    private function saveToCache(array $phpFiles): void
    {
        if ($this->cacheManager === null || $this->registry === null) {
            return;
        }

        // Update file registry
        $this->registry->updateFiles($phpFiles);
        $this->cacheManager->saveRegistry();

        // Save dependency graph
        $graphData = [
            'dependencyGraph' => $this->dependencyGraph,
            'classToFileMap' => $this->classToFileMap,
            'reverseDependencyGraph' => $this->reverseDependencyGraph,
        ];
        $this->cacheManager->saveGraph($graphData);
    }

    /**
     * Clear all cache
     */
    public function clearCache(): bool
    {
        $this->initializeCache();
        return $this->cacheManager?->clear() ?? false;
    }

    /**
     * Get cache statistics
     */
    public function getCacheStats(): array
    {
        $this->initializeCache();

        $stats = [
            'cache_enabled' => $this->cacheEnabled,
            'files_parsed' => $this->filesParsed,
            'files_from_cache' => $this->filesFromCache,
        ];

        if ($this->cacheManager !== null) {
            $stats = array_merge($stats, $this->cacheManager->getStats());
        }

        return $stats;
    }

    private function buildReverseDependencyGraph(): void
    {
        foreach ($this->dependencyGraph as $file => $dependencies) {
            foreach ($dependencies as $dependencyClass) {
                if (!isset($this->classToFileMap[$dependencyClass])) {
                    continue;
                }

                $dependencyFile = $this->classToFileMap[$dependencyClass];

                if (!isset($this->reverseDependencyGraph[$dependencyFile])) {
                    $this->reverseDependencyGraph[$dependencyFile] = [];
                }

                $this->reverseDependencyGraph[$dependencyFile][] = $file;
            }
        }
    }

    public function getAffectedFiles(array $changedFiles): array
    {
        $affected = [];

        foreach ($changedFiles as $changedFile) {
            $affected[$changedFile] = true;
            $this->collectAffectedFilesRecursive($changedFile, $affected);
        }

        return array_keys($affected);
    }

    private function collectAffectedFilesRecursive(string $file, array &$affected): void
    {
        if (!isset($this->reverseDependencyGraph[$file])) {
            return;
        }

        foreach ($this->reverseDependencyGraph[$file] as $dependent) {
            if (isset($affected[$dependent])) {
                continue;
            }

            $affected[$dependent] = true;
            $this->collectAffectedFilesRecursive($dependent, $affected);
        }
    }

    private function getAbsolutePath(string $file): string
    {
        if (str_starts_with($file, '/')) {
            return $file;
        }

        return $this->projectRoot . '/' . $file;
    }
}
