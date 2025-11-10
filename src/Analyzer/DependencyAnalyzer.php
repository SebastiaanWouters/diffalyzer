<?php

declare(strict_types=1);

namespace Diffalyzer\Analyzer;

use Diffalyzer\Cache\CacheInvalidator;
use Diffalyzer\Cache\CacheManager;
use Diffalyzer\Cache\FileHashRegistry;
use Diffalyzer\Parser\AstBasedParser;
use Diffalyzer\Parser\MethodCallExtractor;
use Diffalyzer\Parser\ParserInterface;
use Diffalyzer\Parser\TokenBasedParser;
use Diffalyzer\Strategy\StrategyInterface;

final class DependencyAnalyzer
{
    private array $dependencyGraph = [];
    private array $reverseDependencyGraph = [];
    private array $classToFileMap = [];
    private array $fileToClassesMap = []; // Reverse index: file => [class1, class2, ...] for O(1) cleanup
    private array $methodCallGraph = []; // method => [called methods]
    private array $reverseMethodCallGraph = []; // method => [methods that call it]
    private ?CacheManager $cacheManager = null;
    private ?FileHashRegistry $registry = null;
    private ?CacheInvalidator $invalidator = null;
    private bool $cacheEnabled = true;
    private int $filesParsed = 0;
    private int $filesFromCache = 0;
    private ParserInterface $parser;
    private MethodCallExtractor $methodExtractor;

    public function __construct(
        private readonly string $projectRoot,
        private readonly StrategyInterface $strategy,
        ?string $parserType = null
    ) {
        // Default to token-based parser for 5-10x speedup
        $this->parser = $this->createParser($parserType ?? 'token');
        $this->methodExtractor = new MethodCallExtractor();
    }

    /**
     * Create parser instance based on type
     */
    private function createParser(string $type): ParserInterface
    {
        return match (strtolower($type)) {
            'ast' => new AstBasedParser(),
            'token' => new TokenBasedParser(),
            default => new TokenBasedParser(),
        };
    }

    /**
     * Set the parser type to use (for testing and configuration)
     */
    public function setParserType(string $type): void
    {
        $this->parser = $this->createParser($type);
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
        $this->fileToClassesMap = $cachedData['fileToClassesMap'] ?? [];
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
            $this->classToFileMap,
            $this->fileToClassesMap
        );
        $this->dependencyGraph = $cleaned['dependencyGraph'];
        $this->classToFileMap = $cleaned['classToFileMap'];
        $this->fileToClassesMap = $cleaned['fileToClassesMap'];

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
        $this->fileToClassesMap = array_merge(
            $this->fileToClassesMap,
            $results['fileToClassesMap'] ?? []
        );

        $this->filesParsed += count($phpFiles);
    }

    /**
     * Parse files sequentially using configured parser
     */
    private function parseFilesSequential(array $phpFiles, bool $isIncremental): void
    {
        foreach ($phpFiles as $file) {
            $absolutePath = $this->getAbsolutePath($file);
            if (!file_exists($absolutePath)) {
                continue;
            }

            $code = file_get_contents($absolutePath);
            if ($code === false) {
                continue;
            }

            // Use configured parser (token-based or AST-based)
            $result = $this->parser->parse($code);

            // Remove old class mappings for this file (in incremental mode)
            // Optimized: O(k) instead of O(m×n) where k = classes per file
            if ($isIncremental && isset($this->fileToClassesMap[$file])) {
                foreach ($this->fileToClassesMap[$file] as $class) {
                    unset($this->classToFileMap[$class]);
                }
                unset($this->fileToClassesMap[$file]);
            }

            // Add new class mappings and maintain reverse index
            $declaredClasses = $result->getDeclaredClasses();
            foreach ($declaredClasses as $className) {
                $this->classToFileMap[$className] = $file;
            }
            if (!empty($declaredClasses)) {
                $this->fileToClassesMap[$file] = $declaredClasses;
            }

            $dependencies = $this->strategy->extractDependenciesFromResult($result);

            // Process include/require statements as file-to-file dependencies
            $includes = $result->getIncludes();
            if (!empty($includes)) {
                $resolvedIncludes = $this->resolveIncludePaths($file, $includes);
                $dependencies = array_merge($dependencies, $resolvedIncludes);
            }

            $this->dependencyGraph[$file] = $dependencies;

            $this->filesParsed++;
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
            'fileToClassesMap' => $this->fileToClassesMap,
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

    /**
     * Resolve include/require paths relative to the source file
     *
     * @param string $sourceFile The file containing the include statement (project-relative)
     * @param array $includePaths Array of file paths from include/require statements
     * @return array Array of resolved project-relative file paths
     */
    private function resolveIncludePaths(string $sourceFile, array $includePaths): array
    {
        $resolved = [];
        $sourceDir = dirname($this->getAbsolutePath($sourceFile));

        foreach ($includePaths as $includePath) {
            // Handle absolute paths
            if (str_starts_with($includePath, '/')) {
                // Convert to project-relative path
                $relativePath = str_starts_with($includePath, $this->projectRoot)
                    ? substr($includePath, strlen($this->projectRoot) + 1)
                    : $includePath;
                $resolved[] = $relativePath;
                continue;
            }

            // Resolve relative paths relative to source file directory
            $absoluteInclude = realpath($sourceDir . '/' . $includePath);
            if ($absoluteInclude === false || !file_exists($absoluteInclude)) {
                // Skip unresolvable paths (might be dynamic or missing)
                continue;
            }

            // Convert to project-relative path
            if (str_starts_with($absoluteInclude, $this->projectRoot)) {
                $relativePath = substr($absoluteInclude, strlen($this->projectRoot) + 1);
                $resolved[] = $relativePath;
            }
        }

        return $resolved;
    }

    /**
     * Build method call graph for method-level dependency tracking
     */
    public function buildMethodCallGraph(array $phpFiles): void
    {
        $this->methodCallGraph = [];

        foreach ($phpFiles as $file) {
            $absolutePath = $this->getAbsolutePath($file);
            if (!file_exists($absolutePath)) {
                continue;
            }

            $code = file_get_contents($absolutePath);
            if ($code === false) {
                continue;
            }

            $methodCalls = $this->methodExtractor->extract($code);
            foreach ($methodCalls as $caller => $callees) {
                if (!isset($this->methodCallGraph[$caller])) {
                    $this->methodCallGraph[$caller] = [];
                }
                $this->methodCallGraph[$caller] = array_merge(
                    $this->methodCallGraph[$caller],
                    $callees
                );
            }
        }

        $this->buildReverseMethodCallGraph();
    }

    /**
     * Build reverse method call graph (which methods call each method)
     */
    private function buildReverseMethodCallGraph(): void
    {
        $this->reverseMethodCallGraph = [];

        foreach ($this->methodCallGraph as $caller => $callees) {
            foreach ($callees as $callee) {
                // Skip unresolved calls (like $var->method())
                if (str_contains($callee, '$')) {
                    continue;
                }

                if (!isset($this->reverseMethodCallGraph[$callee])) {
                    $this->reverseMethodCallGraph[$callee] = [];
                }

                $this->reverseMethodCallGraph[$callee][] = $caller;
            }
        }
    }

    /**
     * Get affected methods from changed methods
     *
     * @param array<string, array<string>> $changedMethods File => [method names]
     * @return array<string> Fully qualified method names (Class::method)
     */
    public function getAffectedMethods(array $changedMethods): array
    {
        $affected = [];

        // Convert file => methods to fully qualified method names
        foreach ($changedMethods as $file => $methods) {
            foreach ($methods as $method) {
                $affected[$method] = true;
                $this->collectAffectedMethodsRecursive($method, $affected);
            }
        }

        return array_keys($affected);
    }

    /**
     * Recursively collect methods affected by a changed method
     */
    private function collectAffectedMethodsRecursive(string $method, array &$affected): void
    {
        if (!isset($this->reverseMethodCallGraph[$method])) {
            return;
        }

        foreach ($this->reverseMethodCallGraph[$method] as $caller) {
            if (isset($affected[$caller])) {
                continue;
            }

            $affected[$caller] = true;
            $this->collectAffectedMethodsRecursive($caller, $affected);
        }
    }

    /**
     * Get the method call graph (for debugging/inspection)
     */
    public function getMethodCallGraph(): array
    {
        return $this->methodCallGraph;
    }

    /**
     * Get the reverse method call graph (for debugging/inspection)
     */
    public function getReverseMethodCallGraph(): array
    {
        return $this->reverseMethodCallGraph;
    }

    /**
     * Get the class to file mapping
     */
    public function getClassToFileMap(): array
    {
        return $this->classToFileMap;
    }
}
