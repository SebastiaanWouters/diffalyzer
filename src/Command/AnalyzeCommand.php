<?php

declare(strict_types=1);

namespace Diffalyzer\Command;

use Diffalyzer\Analyzer\DependencyAnalyzer;
use Diffalyzer\Analyzer\TestMethodAnalyzer;
use Diffalyzer\Config\ConfigLoader;
use Diffalyzer\Formatter\FormatterInterface;
use Diffalyzer\Formatter\MethodAwareFormatterInterface;
use Diffalyzer\Formatter\PhpStanFormatter;
use Diffalyzer\Formatter\PhpUnitFormatter;
use Diffalyzer\Formatter\PsalmFormatter;
use Diffalyzer\Git\ChangeDetector;
use Diffalyzer\Matcher\FullScanMatcher;
use Diffalyzer\Scanner\ProjectScanner;
use Diffalyzer\Strategy\ConservativeStrategy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class AnalyzeCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->setName('analyze')
            ->setDescription('Analyze PHP file dependencies from git changes')
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output format: "phpunit" for test files, "psalm" or "phpstan" for all files (default: phpunit)',
                'phpunit'
            )
            ->addOption(
                'from',
                null,
                InputOption::VALUE_OPTIONAL,
                'Source ref for comparison (branch or commit hash)'
            )
            ->addOption(
                'to',
                null,
                InputOption::VALUE_OPTIONAL,
                'Target ref for comparison (branch or commit hash)'
            )
            ->addOption(
                'staged',
                null,
                InputOption::VALUE_NONE,
                'Only analyze staged files'
            )
            ->addOption(
                'full-scan-pattern',
                null,
                InputOption::VALUE_OPTIONAL,
                'Regex pattern to trigger full scan'
            )
            ->addOption(
                'test-pattern',
                null,
                InputOption::VALUE_OPTIONAL,
                'Custom regex pattern to match test files (default: Test.php files and tests/ directories)'
            )
            ->addOption(
                'no-cache',
                null,
                InputOption::VALUE_NONE,
                'Disable cache and force full rebuild of dependency graph'
            )
            ->addOption(
                'clear-cache',
                null,
                InputOption::VALUE_NONE,
                'Clear cache before analysis'
            )
            ->addOption(
                'cache-stats',
                null,
                InputOption::VALUE_NONE,
                'Show cache statistics after analysis'
            )
            ->addOption(
                'parallel',
                'p',
                InputOption::VALUE_OPTIONAL,
                'Number of parallel workers for parsing (default: auto-detect CPU count)'
            )
            ->addOption(
                'config',
                'c',
                InputOption::VALUE_OPTIONAL,
                'Path to config file (default: auto-detect .diffalyzer.yml, diffalyzer.yml, or config.yml)'
            )
            ->addOption(
                'method-level',
                'm',
                InputOption::VALUE_NONE,
                'Enable method-level granularity (default: enabled, use --file-level to disable)'
            )
            ->addOption(
                'file-level',
                null,
                InputOption::VALUE_NONE,
                'Use file-level granularity instead of method-level (less precise but faster)'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = getcwd();
        if ($projectRoot === false) {
            $output->writeln('<error>Could not determine project root</error>');
            return Command::FAILURE;
        }

        $outputFormat = $input->getOption('output');
        if (!in_array($outputFormat, ['phpunit', 'psalm', 'phpstan', 'test'], true)) {
            $output->writeln('<error>Invalid output format: "' . $outputFormat . '". Supported formats: phpunit, psalm, phpstan</error>');
            return Command::FAILURE;
        }

        // 'test' is deprecated but still supported for backward compatibility
        if ($outputFormat === 'test') {
            $outputFormat = 'phpunit';
        }

        // Use unified strategy (Conservative strategy has all dependencies)
        $strategy = new ConservativeStrategy();

        $from = $input->getOption('from');
        $to = $input->getOption('to');
        $staged = $input->getOption('staged');
        $fullScanPattern = $input->getOption('full-scan-pattern');
        $testPattern = $input->getOption('test-pattern');
        $noCache = $input->getOption('no-cache');
        $clearCache = $input->getOption('clear-cache');
        $showCacheStats = $input->getOption('cache-stats');
        $parallelWorkers = $input->getOption('parallel');
        $configPath = $input->getOption('config');
        $fileLevel = $input->getOption('file-level');
        // Method-level is default, unless --file-level is specified
        $methodLevel = !$fileLevel;
        $verbose = $output->isVerbose();

        // Get stderr for verbose output (separate from stdout)
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if ($staged && ($from !== null || $to !== null)) {
            $output->writeln('<error>Cannot use --staged with --from or --to</error>');
            return Command::FAILURE;
        }

        // Validate full-scan-pattern
        if ($fullScanPattern !== null) {
            // Check if it's a valid regex (starts with / or #) or glob pattern
            $isRegexPattern = str_starts_with($fullScanPattern, '/') || str_starts_with($fullScanPattern, '#');

            if ($isRegexPattern) {
                // Validate regex syntax
                if (@preg_match($fullScanPattern, '') === false) {
                    $output->writeln('<error>Invalid regex pattern for --full-scan-pattern: "' . $fullScanPattern . '"</error>');
                    $output->writeln('<info>Regex patterns must start with / or # and have valid syntax.</info>');
                    $output->writeln('<info>Examples: /\.xml$/ or /^config\//</info>');
                    return Command::FAILURE;
                }
            } else {
                // It's a glob pattern - provide helpful feedback
                if ($verbose) {
                    $stderr->writeln('[diffalyzer] Using glob pattern for full-scan: "' . $fullScanPattern . '"');
                }
            }
        }

        if ($testPattern !== null && @preg_match($testPattern, '') === false) {
            $output->writeln('<error>Invalid regex pattern for --test-pattern</error>');
            return Command::FAILURE;
        }

        try {
            $startTime = microtime(true);

            // Load configuration
            $configLoader = new ConfigLoader();
            $config = $configLoader->load($configPath);
            $configPatterns = $config['full_scan_patterns'];

            if ($verbose) {
                if ($fullScanPattern !== null) {
                    $stderr->writeln('[diffalyzer] Using CLI full-scan pattern (overrides config): "' . $fullScanPattern . '"');
                } elseif ($configPatterns !== null) {
                    if (empty($configPatterns)) {
                        $stderr->writeln('[diffalyzer] Full-scan patterns disabled via config (empty array)');
                    } else {
                        $stderr->writeln(sprintf(
                            '[diffalyzer] Loaded %d full-scan pattern(s) from config',
                            count($configPatterns)
                        ));
                    }
                } else {
                    $stderr->writeln('[diffalyzer] Using built-in full-scan patterns (no config file found)');
                }
            }

            // Initialize analyzer
            $analyzer = new DependencyAnalyzer($projectRoot, $strategy);

            // Handle cache options
            if ($clearCache) {
                if ($verbose) {
                    $stderr->writeln('[diffalyzer] Clearing cache...');
                }
                $analyzer->clearCache();
            }

            if ($noCache) {
                $analyzer->setCacheEnabled(false);
            }

            $changeDetector = new ChangeDetector($projectRoot);
            $allChangedFiles = $changeDetector->getAllChangedFiles($from, $to, $staged);

            if ($verbose) {
                $stderr->writeln(sprintf(
                    '[diffalyzer] Detected %d changed file(s)',
                    count($allChangedFiles)
                ));
                if (!empty($allChangedFiles)) {
                    foreach ($allChangedFiles as $file) {
                        $stderr->writeln('  - ' . $file);
                    }
                }
            }

            $fullScanMatcher = new FullScanMatcher($configPatterns);
            $shouldFullScan = $fullScanMatcher->shouldTriggerFullScan($allChangedFiles, $fullScanPattern);

            // Provide helpful feedback if CLI pattern was provided but didn't match
            if ($verbose && $fullScanPattern !== null && !$shouldFullScan && !empty($allChangedFiles)) {
                $stderr->writeln('[diffalyzer] Warning: CLI pattern "' . $fullScanPattern . '" did not match any changed files');
                $stderr->writeln('[diffalyzer] Full scan was NOT triggered. Proceeding with partial analysis.');
            }

            $changedFiles = array_filter($allChangedFiles, fn(string $file): bool => str_ends_with($file, '.php'));

            if (empty($changedFiles) && !$shouldFullScan) {
                if ($verbose) {
                    $stderr->writeln('[diffalyzer] No changes detected');
                }
                $output->write('');
                return Command::SUCCESS;
            }

            $affectedFiles = [];
            $affectedMethods = [];

            if ($shouldFullScan) {
                $match = $fullScanMatcher->getLastMatch();
                if ($verbose && $match !== null) {
                    $stderr->writeln(sprintf(
                        '[diffalyzer] Full scan triggered: "%s" matched pattern "%s"',
                        $match['file'],
                        $match['pattern']
                    ));
                }
                // For full scan, we need a formatter but don't need classToFileMap populated
                $formatter = $this->createFormatter($outputFormat, $projectRoot, [], $testPattern);
                $output->write($formatter->format([], true));
                return Command::SUCCESS;
            }

            if ($verbose) {
                $stderr->writeln(sprintf(
                    '[diffalyzer] Analyzing %d PHP file(s)...',
                    count($changedFiles)
                ));
            }

            $scanStartTime = microtime(true);
            $scanner = new ProjectScanner($projectRoot);
            $allPhpFiles = $scanner->getAllPhpFiles();
            $scanDuration = microtime(true) - $scanStartTime;

            if ($verbose) {
                $stderr->writeln(sprintf(
                    '[diffalyzer] Scanned project: found %d PHP file(s) (%.2fs)',
                    count($allPhpFiles),
                    $scanDuration
                ));
            }

            $parseStartTime = microtime(true);
            $analyzer->buildDependencyGraph($allPhpFiles);
            $parseDuration = microtime(true) - $parseStartTime;

            if ($verbose) {
                $stderr->writeln(sprintf(
                    '[diffalyzer] Built dependency graph (%.2fs)',
                    $parseDuration
                ));
            }

            // Get classToFileMap AFTER building dependency graph (it's populated during build)
            $classToFileMap = $analyzer->getClassToFileMap();
            $formatter = $this->createFormatter($outputFormat, $projectRoot, $classToFileMap, $testPattern);

            // Method-level or file-level analysis
            if ($methodLevel) {
                // Method-level granularity: only tests that call changed methods
                if ($verbose) {
                    $stderr->writeln('[diffalyzer] Using method-level granularity');
                }

                // Get changed methods
                $methodDetectStart = microtime(true);
                $changedMethods = $changeDetector->getChangedMethods($from, $to, $staged);
                $methodDetectDuration = microtime(true) - $methodDetectStart;

                if ($verbose) {
                    $methodCount = array_sum(array_map('count', $changedMethods));
                    $stderr->writeln(sprintf(
                        '[diffalyzer] Detected %d changed method(s) in %d file(s) (%.2fs)',
                        $methodCount,
                        count($changedMethods),
                        $methodDetectDuration
                    ));
                }

                if (empty($changedMethods)) {
                    if ($verbose) {
                        $stderr->writeln('[diffalyzer] No method changes detected');
                    }
                    $output->write('');
                    return Command::SUCCESS;
                }

                // Build method call graph
                $methodGraphStart = microtime(true);
                $analyzer->buildMethodCallGraph($allPhpFiles);
                $methodGraphDuration = microtime(true) - $methodGraphStart;

                if ($verbose) {
                    $stderr->writeln(sprintf(
                        '[diffalyzer] Built method call graph (%.2fs)',
                        $methodGraphDuration
                    ));
                }

                // Get affected methods
                $allAffectedMethods = $analyzer->getAffectedMethods($changedMethods);

                if ($verbose) {
                    $stderr->writeln(sprintf(
                        '[diffalyzer] Found %d affected method(s)',
                        count($allAffectedMethods)
                    ));
                }

                // For PHPUnit format: filter to test methods only
                // For Psalm format: use all affected methods
                if ($outputFormat === 'phpunit') {
                    // Analyze test files
                    $testAnalyzer = new TestMethodAnalyzer();
                    $testFiles = array_filter($allPhpFiles, fn($f) => $testAnalyzer->isTestFile($f));

                    $testAnalysisStart = microtime(true);
                    $testAnalysis = $testAnalyzer->analyzeTestFiles($testFiles, $projectRoot);
                    $testAnalysisDuration = microtime(true) - $testAnalysisStart;

                    if ($verbose) {
                        $stderr->writeln(sprintf(
                            '[diffalyzer] Analyzed %d test file(s), found %d test method(s) (%.2fs)',
                            count($testFiles),
                            count($testAnalysis['testMethods']),
                            $testAnalysisDuration
                        ));
                    }

                    // Find relevant tests
                    $relevantTestMethods = $testAnalyzer->findTestMethodsForAffectedMethods(
                        $allAffectedMethods,
                        $testAnalysis['testMethods']
                    );

                    // If no tests directly call the changed methods, fall back to finding tests
                    // that use the changed classes (broader matching)
                    if (empty($relevantTestMethods) && !empty($changedMethods)) {
                        // Extract short class names from changed methods
                        $shortClassNames = $this->extractClassesFromMethods(array_merge(...array_values($changedMethods)));

                        // Map short class names to fully qualified names
                        $changedClasses = $this->mapShortNamesToFQN($shortClassNames, $classToFileMap);

                        if ($verbose) {
                            $stderr->writeln(sprintf(
                                '[diffalyzer] No direct method callers found, using class-level matching (%d class(es))',
                                count($changedClasses)
                            ));
                        }

                        $relevantTestMethods = $testAnalyzer->findTestMethodsForClasses(
                            $changedClasses,
                            $testAnalysis['testMethods']
                        );

                        // If still no tests found, fall back to namespace/directory matching
                        if (empty($relevantTestMethods)) {
                            if ($verbose) {
                                $stderr->writeln('[diffalyzer] No class-level matches, using namespace/directory matching');
                            }

                            $relevantTestMethods = $testAnalyzer->findTestMethodsByNamespace(
                                array_keys($changedMethods),
                                $testAnalysis['testMethods']
                            );
                        }
                    }

                    if ($verbose) {
                        $stderr->writeln(sprintf(
                            '[diffalyzer] Found %d relevant test method(s)',
                            count($relevantTestMethods)
                        ));
                    }

                    // Store test methods for method-level output
                    $affectedMethods = $relevantTestMethods;

                    // Map back to files (for backward compatibility with file-level output)
                    $affectedFiles = $testAnalyzer->mapTestMethodsToFiles(
                        $relevantTestMethods,
                        $testAnalysis['testFiles']
                    );

                    if ($verbose) {
                        $stderr->writeln(sprintf(
                            '[diffalyzer] Narrowed down to %d test file(s)',
                            count($affectedFiles)
                        ));
                    }
                } else {
                    // For Psalm/PHPStan: use all affected methods
                    $affectedMethods = $allAffectedMethods;

                    // Map methods to files for file-level fallback
                    $filesFromMethods = [];

                    // First, ALWAYS include the originally changed files themselves
                    // (these files have changed methods and should always be in the output)
                    foreach ($changedMethods as $file => $methods) {
                        if (!empty($methods)) {
                            $filesFromMethods[$file] = true;
                        }
                    }

                    // Then, add files with methods that are affected (methods that call the changed methods)
                    foreach ($affectedMethods as $method) {
                        if (str_contains($method, '::')) {
                            [$className] = explode('::', $method, 2);
                            if (isset($classToFileMap[$className])) {
                                $filesFromMethods[$classToFileMap[$className]] = true;
                            }
                        }
                    }
                    $affectedFiles = array_keys($filesFromMethods);

                    if ($verbose) {
                        $changedFileCount = count(array_intersect_key($filesFromMethods, $changedMethods));
                        $dependentFileCount = count($affectedFiles) - $changedFileCount;
                        $stderr->writeln(sprintf(
                            '[diffalyzer] Mapped to %d affected file(s) (%d changed + %d dependent)',
                            count($affectedFiles),
                            $changedFileCount,
                            $dependentFileCount
                        ));
                    }
                }
            } else {
                // File-level granularity (original behavior)
                $affectedFiles = $analyzer->getAffectedFiles($changedFiles);

                if ($verbose) {
                    $stderr->writeln(sprintf(
                        '[diffalyzer] Found %d affected file(s)',
                        count($affectedFiles)
                    ));
                }
            }

            // Output affected methods or files depending on formatter capability and analysis mode
            // For PHPUnit: use method-level output if available (allows running specific test methods)
            // For Psalm/PHPStan: use file-level output (static analyzers work on whole files)
            if ($methodLevel && $outputFormat === 'phpunit' && $formatter instanceof MethodAwareFormatterInterface && !empty($affectedMethods)) {
                $result = $formatter->formatMethods($affectedMethods, false);
            } else {
                $result = $formatter->format($affectedFiles, false);
            }

            // Provide diagnostic information if output is empty
            if (empty($result)) {
                if ($outputFormat === 'phpunit' && !empty($affectedFiles)) {
                    $stderr->writeln('<comment>[diffalyzer] No test files found in affected files. Files changed but no matching tests detected.</comment>');
                    $stderr->writeln('<comment>[diffalyzer] Hint: Check your test file patterns or use --output=psalm to see all affected files.</comment>');
                } elseif (empty($affectedFiles) && !empty($changedFiles)) {
                    $stderr->writeln('<comment>[diffalyzer] Files changed but no dependencies affected. This might indicate isolated changes.</comment>');
                } elseif ($methodLevel && empty($affectedMethods) && !empty($changedFiles)) {
                    $stderr->writeln('<comment>[diffalyzer] No affected methods detected. Changes may not impact tracked method calls.</comment>');
                    $stderr->writeln('<comment>[diffalyzer] Hint: Try --file-level for broader analysis or --verbose for more details.</comment>');
                }
            }

            $output->write($result);

            // Show performance metrics
            $totalDuration = microtime(true) - $startTime;

            if ($verbose || $showCacheStats) {
                $stats = $analyzer->getCacheStats();

                if ($verbose) {
                    $stderr->writeln('');
                    $stderr->writeln('[diffalyzer] Performance Metrics:');
                    $stderr->writeln(sprintf('  Total time: %.2fs', $totalDuration));
                    $stderr->writeln(sprintf('  Scan time: %.2fs', $scanDuration));
                    $stderr->writeln(sprintf('  Parse time: %.2fs', $parseDuration));
                    $stderr->writeln(sprintf('  Changed files: %d', count($changedFiles)));
                    $stderr->writeln(sprintf('  Affected files: %d', count($affectedFiles)));
                }

                if ($showCacheStats) {
                    $stderr->writeln('');
                    $stderr->writeln('[diffalyzer] Cache Statistics:');
                    $stderr->writeln(sprintf('  Cache enabled: %s', $stats['cache_enabled'] ? 'yes' : 'no'));

                    if ($stats['cache_enabled']) {
                        $stderr->writeln(sprintf('  Files parsed: %d', $stats['files_parsed']));
                        $stderr->writeln(sprintf('  Files from cache: %d', $stats['files_from_cache']));

                        if (isset($stats['tracked_files'])) {
                            $stderr->writeln(sprintf('  Total tracked files: %d', $stats['tracked_files']));
                        }

                        if (isset($stats['cache_age_seconds'])) {
                            $stderr->writeln(sprintf('  Cache age: %ds', $stats['cache_age_seconds']));
                        }

                        if ($stats['files_parsed'] > 0 && $stats['files_from_cache'] > 0) {
                            $totalFiles = $stats['files_parsed'] + $stats['files_from_cache'];
                            $cacheHitRate = ($stats['files_from_cache'] / $totalFiles) * 100;
                            $stderr->writeln(sprintf('  Cache hit rate: %.1f%%', $cacheHitRate));

                            // Estimate time saved
                            if ($parseDuration > 0 && $stats['files_parsed'] > 0) {
                                $timePerFile = $parseDuration / $stats['files_parsed'];
                                $timeSaved = $timePerFile * $stats['files_from_cache'];
                                $stderr->writeln(sprintf('  Estimated time saved: %.2fs', $timeSaved));
                            }
                        }
                    }
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $stderr->writeln('<error>[diffalyzer] Error: ' . $e->getMessage() . '</error>');
            if ($verbose) {
                $stderr->writeln('<error>Stack trace:</error>');
                $stderr->writeln($e->getTraceAsString());
            }
            return Command::FAILURE;
        }
    }

    private function createFormatter(
        string $format,
        string $projectRoot,
        array $classToFileMap,
        ?string $testPattern
    ): FormatterInterface {
        return match ($format) {
            'phpunit' => new PhpUnitFormatter($projectRoot, $classToFileMap, $testPattern),
            'psalm' => new PsalmFormatter($classToFileMap),
            'phpstan' => new PhpStanFormatter($classToFileMap),
            default => new PhpUnitFormatter($projectRoot, $classToFileMap, $testPattern),
        };
    }

    /**
     * Extract class names from fully qualified method names
     *
     * @param array<string> $methods Method names like "Namespace\Class::method" or "Class::method"
     * @return array<string> Class names like "Namespace\Class" or "Class"
     */
    private function extractClassesFromMethods(array $methods): array
    {
        $classes = [];
        foreach ($methods as $method) {
            if (str_contains($method, '::')) {
                [$className] = explode('::', $method, 2);
                $classes[] = $className;
            }
        }
        return array_unique($classes);
    }

    /**
     * Map short class names to fully qualified names
     *
     * @param array<string> $shortNames Short class names like "Country", "Command"
     * @param array<string, string> $classToFileMap FQN => file path map
     * @return array<string> Fully qualified class names
     */
    private function mapShortNamesToFQN(array $shortNames, array $classToFileMap): array
    {
        $fqnList = [];

        foreach ($shortNames as $shortName) {
            // If it's already a fully qualified name (contains \), use it as is
            if (str_contains($shortName, '\\')) {
                $fqnList[] = $shortName;
                continue;
            }

            // Search for matching fully qualified names
            foreach (array_keys($classToFileMap) as $fqn) {
                // Get the short name from the FQN (last part after last \)
                $fqnShortName = str_contains($fqn, '\\')
                    ? substr($fqn, strrpos($fqn, '\\') + 1)
                    : $fqn;

                if ($fqnShortName === $shortName) {
                    $fqnList[] = $fqn;
                }
            }
        }

        return array_unique($fqnList);
    }
}
