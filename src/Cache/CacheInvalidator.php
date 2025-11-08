<?php

declare(strict_types=1);

namespace Diffalyzer\Cache;

/**
 * Handles smart cache invalidation when files change
 */
class CacheInvalidator
{
    /**
     * Invalidate cache entries for changed files and their dependents
     *
     * @param array $changedFiles Array of changed file paths
     * @param array $dependencyGraph Current dependency graph [file => [classes]]
     * @param array $reverseDependencyGraph Reverse graph [file => [dependentFiles]]
     * @return array Files that need to be reparsed
     */
    public function getFilesToReparse(
        array $changedFiles,
        array $dependencyGraph,
        array $reverseDependencyGraph
    ): array {
        $toReparse = [];

        foreach ($changedFiles as $file) {
            // Add the changed file itself
            $toReparse[$file] = true;

            // Add all files that depend on this file (transitively)
            $this->collectDependents($file, $reverseDependencyGraph, $toReparse);
        }

        return array_keys($toReparse);
    }

    /**
     * Remove entries for files that no longer exist
     *
     * @param array $currentFiles Currently existing files
     * @param array $dependencyGraph Dependency graph to clean
     * @param array $classToFileMap Class to file mapping to clean
     * @param array $fileToClassesMap File to classes mapping to clean
     * @return array Cleaned data structures
     */
    public function removeDeletedFiles(
        array $currentFiles,
        array $dependencyGraph,
        array $classToFileMap,
        array $fileToClassesMap = []
    ): array {
        $currentFilesSet = array_flip($currentFiles);

        // Remove deleted files from dependency graph
        $cleanedGraph = [];
        foreach ($dependencyGraph as $file => $dependencies) {
            if (isset($currentFilesSet[$file])) {
                $cleanedGraph[$file] = $dependencies;
            }
        }

        // Remove deleted files from class-to-file map
        $cleanedClassMap = [];
        foreach ($classToFileMap as $class => $file) {
            if (isset($currentFilesSet[$file])) {
                $cleanedClassMap[$class] = $file;
            }
        }

        // Remove deleted files from file-to-classes map
        $cleanedFileToClassesMap = [];
        foreach ($fileToClassesMap as $file => $classes) {
            if (isset($currentFilesSet[$file])) {
                $cleanedFileToClassesMap[$file] = $classes;
            }
        }

        return [
            'dependencyGraph' => $cleanedGraph,
            'classToFileMap' => $cleanedClassMap,
            'fileToClassesMap' => $cleanedFileToClassesMap,
        ];
    }

    /**
     * Invalidate cache for files affected by changed dependencies
     *
     * @param array $changedFiles Files that changed
     * @param array $classToFileMap Mapping of classes to files (for backward compatibility)
     * @param array $dependencyGraph Dependency graph
     * @param array $fileToClassesMap File to classes mapping (optimized reverse lookup)
     * @return array Additional files that might be affected
     */
    public function getIndirectlyAffectedFiles(
        array $changedFiles,
        array $classToFileMap,
        array $dependencyGraph,
        array $fileToClassesMap = []
    ): array {
        $affected = [];

        // For each changed file, find what classes it defines
        // Then find all files that depend on those classes
        foreach ($changedFiles as $changedFile) {
            // Use optimized reverse map if available, otherwise fall back to O(n) scan
            $definedClasses = $this->getClassesDefinedInFile(
                $changedFile,
                $fileToClassesMap,
                $classToFileMap
            );

            foreach ($definedClasses as $class) {
                // Find all files that import/use this class
                foreach ($dependencyGraph as $file => $dependencies) {
                    if (in_array($class, $dependencies, true)) {
                        $affected[$file] = true;
                    }
                }
            }
        }

        return array_keys($affected);
    }

    /**
     * Recursively collect all files that depend on the given file
     */
    private function collectDependents(
        string $file,
        array $reverseDependencyGraph,
        array &$collected
    ): void {
        if (!isset($reverseDependencyGraph[$file])) {
            return;
        }

        foreach ($reverseDependencyGraph[$file] as $dependent) {
            if (isset($collected[$dependent])) {
                continue; // Already processed (cycle detection)
            }

            $collected[$dependent] = true;
            $this->collectDependents($dependent, $reverseDependencyGraph, $collected);
        }
    }

    /**
     * Get all classes defined in a file
     * Optimized: O(1) lookup when fileToClassesMap is available, O(n) fallback otherwise
     *
     * @param string $file File path
     * @param array $fileToClassesMap Optimized reverse map (file => classes)
     * @param array $classToFileMap Fallback forward map (class => file)
     * @return array List of classes
     */
    private function getClassesDefinedInFile(
        string $file,
        array $fileToClassesMap,
        array $classToFileMap = []
    ): array {
        // Prefer O(1) lookup if reverse map is available
        if (!empty($fileToClassesMap) && isset($fileToClassesMap[$file])) {
            return $fileToClassesMap[$file];
        }

        // Fallback to O(n) scan for backward compatibility
        $classes = [];
        foreach ($classToFileMap as $class => $classFile) {
            if ($classFile === $file) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
