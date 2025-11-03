<?php

declare(strict_types=1);

namespace Diffalyzer\Analyzer;

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
    private array $fileToFileDependencies = [];

    public function __construct(
        private readonly string $projectRoot,
        private readonly StrategyInterface $strategy
    ) {
    }

    public function buildDependencyGraph(array $phpFiles): void
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
                $ast = $parser->parse($code);

                $visitor = new DependencyVisitor();
                $traverser->addVisitor($visitor);
                $traverser->traverse($ast);
                $traverser->removeVisitor($visitor);

                foreach ($visitor->getDeclaredClasses() as $className) {
                    $this->classToFileMap[$className] = $file;
                }

                $dependencies = $this->strategy->extractDependencies($visitor);
                $this->dependencyGraph[$file] = $dependencies;

                $fileRefs = $visitor->getFileReferences();
                if (!empty($fileRefs)) {
                    $this->fileToFileDependencies[$file] = $this->resolveFixturePaths($file, $fileRefs);
                }
            } catch (Error $error) {
                continue;
            }
        }

        $this->buildReverseDependencyGraph();
    }

    private function resolveFixturePaths(string $sourceFile, array $fileReferences): array
    {
        $resolved = [];
        $sourceDir = dirname($this->getAbsolutePath($sourceFile));

        foreach ($fileReferences as $ref) {
            $ref = ltrim($ref, '/');

            $possiblePaths = [
                $ref,
                'tests/fixtures/' . $ref,
                'tests/data/' . $ref,
                'fixtures/' . $ref,
                'data/' . $ref,
            ];

            foreach ($possiblePaths as $path) {
                $absolutePath = $this->projectRoot . '/' . $path;
                if (file_exists($absolutePath)) {
                    $resolved[] = $path;
                    break;
                }

                $relativeToSource = $sourceDir . '/' . $ref;
                if (file_exists($relativeToSource)) {
                    $resolved[] = str_replace($this->projectRoot . '/', '', $relativeToSource);
                    break;
                }
            }
        }

        return $resolved;
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

        foreach ($this->fileToFileDependencies as $file => $referencedFiles) {
            foreach ($referencedFiles as $referencedFile) {
                if (!isset($this->reverseDependencyGraph[$referencedFile])) {
                    $this->reverseDependencyGraph[$referencedFile] = [];
                }

                $this->reverseDependencyGraph[$referencedFile][] = $file;
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
