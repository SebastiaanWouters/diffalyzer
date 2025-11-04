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
     * @return array Cleaned data structures
     */
    public function removeDeletedFiles(
        array $currentFiles,
        array $dependencyGraph,
        array $classToFileMap
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

        return [
            'dependencyGraph' => $cleanedGraph,
            'classToFileMap' => $cleanedClassMap,
        ];
    }

    /**
     * Invalidate cache for files affected by changed dependencies
     *
     * @param array $changedFiles Files that changed
     * @param array $classToFileMap Mapping of classes to files
     * @param array $dependencyGraph Dependency graph
     * @return array Additional files that might be affected
     */
    public function getIndirectlyAffectedFiles(
        array $changedFiles,
        array $classToFileMap,
        array $dependencyGraph
    ): array {
        $affected = [];

        // For each changed file, find what classes it defines
        // Then find all files that depend on those classes
        foreach ($changedFiles as $changedFile) {
            $definedClasses = $this->getClassesDefinedInFile($changedFile, $classToFileMap);

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
     */
    private function getClassesDefinedInFile(string $file, array $classToFileMap): array
    {
        $classes = [];

        foreach ($classToFileMap as $class => $classFile) {
            if ($classFile === $file) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
