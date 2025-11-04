<?php

declare(strict_types=1);

namespace Diffalyzer\Analyzer;

use Diffalyzer\Strategy\StrategyInterface;
use Symfony\Component\Process\Process;

/**
 * Parses PHP files in parallel using multiple processes
 */
class ParallelParser
{
    private int $workerCount;

    public function __construct(
        private readonly string $projectRoot,
        private readonly StrategyInterface $strategy,
        ?int $workerCount = null
    ) {
        // Default to CPU count or 4 workers
        $this->workerCount = $workerCount ?? $this->detectCpuCount();
    }

    /**
     * Parse files in parallel and return results
     *
     * @param array $files Files to parse
     * @return array Results indexed by file: ['dependencyGraph' => [], 'classToFileMap' => []]
     */
    public function parseFiles(array $files): array
    {
        // If only a few files, don't bother with parallelization
        if (count($files) < $this->workerCount * 2) {
            return $this->parseFilesSequentially($files);
        }

        // Split files into chunks for each worker
        $chunks = array_chunk($files, (int) ceil(count($files) / $this->workerCount));

        // Create worker processes
        $processes = [];
        $tempFiles = [];

        foreach ($chunks as $index => $chunk) {
            $tempFile = tempnam(sys_get_temp_dir(), 'diffalyzer_worker_');
            $tempFiles[$index] = $tempFile;

            // Create worker script
            $workerScript = $this->createWorkerScript($chunk, $tempFile);
            $scriptFile = tempnam(sys_get_temp_dir(), 'diffalyzer_script_');
            file_put_contents($scriptFile, $workerScript);

            // Start worker process
            $process = new Process(['php', $scriptFile]);
            $process->setTimeout(300); // 5 minutes timeout
            $process->start();

            $processes[$index] = [
                'process' => $process,
                'script_file' => $scriptFile,
            ];
        }

        // Wait for all processes to complete
        $results = [];
        foreach ($processes as $index => $processData) {
            /** @var Process $process */
            $process = $processData['process'];
            $process->wait();

            // Read results from temp file
            if ($process->isSuccessful() && file_exists($tempFiles[$index])) {
                $data = file_get_contents($tempFiles[$index]);
                if ($data !== false) {
                    $decoded = json_decode($data, true);
                    if (is_array($decoded)) {
                        $results = array_merge($results, $decoded);
                    }
                }
            }

            // Cleanup
            if (file_exists($tempFiles[$index])) {
                unlink($tempFiles[$index]);
            }
            if (file_exists($processData['script_file'])) {
                unlink($processData['script_file']);
            }
        }

        return $this->mergeResults($results);
    }

    /**
     * Parse files sequentially (fallback)
     */
    private function parseFilesSequentially(array $files): array
    {
        // This would require instantiating the full parser logic
        // For now, return empty to indicate sequential parsing should be used
        return [];
    }

    /**
     * Create PHP worker script that parses a chunk of files
     */
    private function createWorkerScript(array $files, string $outputFile): string
    {
        $filesJson = json_encode($files);
        $projectRoot = $this->projectRoot;
        $strategyClass = get_class($this->strategy);

        return <<<PHP
<?php
require_once '{$projectRoot}/vendor/autoload.php';

use Diffalyzer\\Visitor\\DependencyVisitor;
use PhpParser\\Error;
use PhpParser\\NodeTraverser;
use PhpParser\\ParserFactory;
use {$strategyClass};

\$files = json_decode('{$filesJson}', true);
\$projectRoot = '{$projectRoot}';
\$strategy = new {$strategyClass}();

\$results = [];
\$parser = (new ParserFactory())->createForNewestSupportedVersion();
\$traverser = new NodeTraverser();

foreach (\$files as \$file) {
    \$absolutePath = \$projectRoot . '/' . \$file;
    if (!file_exists(\$absolutePath)) {
        continue;
    }

    try {
        \$code = file_get_contents(\$absolutePath);
        if (\$code === false) {
            continue;
        }

        \$ast = \$parser->parse(\$code);
        if (\$ast === null) {
            continue;
        }

        \$visitor = new DependencyVisitor();
        \$traverser->addVisitor(\$visitor);
        \$traverser->traverse(\$ast);
        \$traverser->removeVisitor(\$visitor);

        \$results[\$file] = [
            'declaredClasses' => \$visitor->getDeclaredClasses(),
            'dependencies' => \$strategy->extractDependencies(\$visitor),
        ];
    } catch (Error \$error) {
        continue;
    }
}

file_put_contents('{$outputFile}', json_encode(\$results));
PHP;
    }

    /**
     * Merge results from all workers
     */
    private function mergeResults(array $results): array
    {
        $dependencyGraph = [];
        $classToFileMap = [];

        foreach ($results as $file => $data) {
            if (!isset($data['declaredClasses']) || !isset($data['dependencies'])) {
                continue;
            }

            foreach ($data['declaredClasses'] as $className) {
                $classToFileMap[$className] = $file;
            }

            $dependencyGraph[$file] = $data['dependencies'];
        }

        return [
            'dependencyGraph' => $dependencyGraph,
            'classToFileMap' => $classToFileMap,
        ];
    }

    /**
     * Detect number of CPU cores
     */
    private function detectCpuCount(): int
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $process = new Process(['wmic', 'cpu', 'get', 'NumberOfCores']);
            $process->run();
            $output = $process->getOutput();
            if (preg_match('/(\d+)/', $output, $matches)) {
                return max(1, (int) $matches[1]);
            }
        } else {
            // Linux/Mac
            $process = new Process(['nproc']);
            $process->run();
            $output = trim($process->getOutput());
            if (is_numeric($output)) {
                return max(1, (int) $output);
            }

            // Fallback: try reading /proc/cpuinfo
            if (file_exists('/proc/cpuinfo')) {
                $cpuinfo = file_get_contents('/proc/cpuinfo');
                if (preg_match_all('/^processor/m', $cpuinfo, $matches)) {
                    return max(1, count($matches[0]));
                }
            }
        }

        // Default fallback
        return 4;
    }

    /**
     * Set number of workers
     */
    public function setWorkerCount(int $count): void
    {
        $this->workerCount = max(1, $count);
    }

    /**
     * Get number of workers
     */
    public function getWorkerCount(): int
    {
        return $this->workerCount;
    }
}
