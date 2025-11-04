<?php

declare(strict_types=1);

namespace Diffalyzer\Command;

use Diffalyzer\Analyzer\DependencyAnalyzer;
use Diffalyzer\Formatter\DefaultFormatter;
use Diffalyzer\Formatter\FormatterInterface;
use Diffalyzer\Formatter\PhpUnitFormatter;
use Diffalyzer\Git\ChangeDetector;
use Diffalyzer\Matcher\FullScanMatcher;
use Diffalyzer\Scanner\ProjectScanner;
use Diffalyzer\Strategy\ConservativeStrategy;
use Diffalyzer\Strategy\MinimalStrategy;
use Diffalyzer\Strategy\ModerateStrategy;
use Diffalyzer\Strategy\StrategyInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
                'Output format: test (test files only) or files (all affected files)'
            )
            ->addOption(
                'strategy',
                's',
                InputOption::VALUE_OPTIONAL,
                'Analysis strategy: conservative, moderate, minimal',
                'conservative'
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
        if (!in_array($outputFormat, ['test', 'files'], true)) {
            $output->writeln('<error>Invalid output format. Use: test or files</error>');
            return Command::FAILURE;
        }

        $strategyName = $input->getOption('strategy');
        $strategy = $this->createStrategy($strategyName);
        if ($strategy === null) {
            $output->writeln('<error>Invalid strategy. Use: conservative, moderate, or minimal</error>');
            return Command::FAILURE;
        }

        $from = $input->getOption('from');
        $to = $input->getOption('to');
        $staged = $input->getOption('staged');
        $fullScanPattern = $input->getOption('full-scan-pattern');
        $testPattern = $input->getOption('test-pattern');
        $noCache = $input->getOption('no-cache');
        $clearCache = $input->getOption('clear-cache');
        $showCacheStats = $input->getOption('cache-stats');
        $parallelWorkers = $input->getOption('parallel');
        $verbose = $output->isVerbose();

        if ($staged && ($from !== null || $to !== null)) {
            $output->writeln('<error>Cannot use --staged with --from or --to</error>');
            return Command::FAILURE;
        }

        if ($fullScanPattern !== null && @preg_match($fullScanPattern, '') === false) {
            $output->writeln('<error>Invalid regex pattern for --full-scan-pattern</error>');
            return Command::FAILURE;
        }

        if ($testPattern !== null && @preg_match($testPattern, '') === false) {
            $output->writeln('<error>Invalid regex pattern for --test-pattern</error>');
            return Command::FAILURE;
        }

        try {
            $startTime = microtime(true);

            // Initialize analyzer
            $analyzer = new DependencyAnalyzer($projectRoot, $strategy);

            // Handle cache options
            if ($clearCache) {
                if ($verbose) {
                    $output->writeln('<info>Clearing cache...</info>');
                }
                $analyzer->clearCache();
            }

            if ($noCache) {
                $analyzer->setCacheEnabled(false);
            }

            $changeDetector = new ChangeDetector($projectRoot);
            $allChangedFiles = $changeDetector->getAllChangedFiles($from, $to, $staged);

            $fullScanMatcher = new FullScanMatcher();
            $shouldFullScan = $fullScanMatcher->shouldTriggerFullScan($allChangedFiles, $fullScanPattern);

            $changedFiles = array_filter($allChangedFiles, fn(string $file): bool => str_ends_with($file, '.php'));

            if (empty($changedFiles) && !$shouldFullScan) {
                $output->write('');
                return Command::SUCCESS;
            }

            $formatter = $this->createFormatter($outputFormat, $projectRoot, $testPattern);
            $affectedFiles = [];

            if ($shouldFullScan) {
                $output->write($formatter->format([], true));
                return Command::SUCCESS;
            }

            $scanStartTime = microtime(true);
            $scanner = new ProjectScanner($projectRoot);
            $allPhpFiles = $scanner->getAllPhpFiles();
            $scanDuration = microtime(true) - $scanStartTime;

            if ($verbose) {
                $output->writeln(sprintf(
                    '<info>Found %d PHP files (%.2fs)</info>',
                    count($allPhpFiles),
                    $scanDuration
                ), OutputInterface::VERBOSITY_VERBOSE);
            }

            $parseStartTime = microtime(true);
            $analyzer->buildDependencyGraph($allPhpFiles);
            $parseDuration = microtime(true) - $parseStartTime;

            $affectedFiles = $analyzer->getAffectedFiles($changedFiles);

            $result = $formatter->format($affectedFiles, false);
            $output->write($result);

            // Show performance metrics
            $totalDuration = microtime(true) - $startTime;

            if ($verbose || $showCacheStats) {
                $stats = $analyzer->getCacheStats();

                if ($verbose) {
                    $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
                    $output->writeln('<comment>Performance Metrics:</comment>', OutputInterface::VERBOSITY_VERBOSE);
                    $output->writeln(sprintf('  Total time: %.2fs', $totalDuration), OutputInterface::VERBOSITY_VERBOSE);
                    $output->writeln(sprintf('  Scan time: %.2fs', $scanDuration), OutputInterface::VERBOSITY_VERBOSE);
                    $output->writeln(sprintf('  Parse time: %.2fs', $parseDuration), OutputInterface::VERBOSITY_VERBOSE);
                    $output->writeln(sprintf('  Changed files: %d', count($changedFiles)), OutputInterface::VERBOSITY_VERBOSE);
                    $output->writeln(sprintf('  Affected files: %d', count($affectedFiles)), OutputInterface::VERBOSITY_VERBOSE);
                }

                if ($showCacheStats) {
                    $output->writeln('', OutputInterface::VERBOSITY_VERBOSE);
                    $output->writeln('<comment>Cache Statistics:</comment>', OutputInterface::VERBOSITY_VERBOSE);
                    $output->writeln(sprintf('  Cache enabled: %s', $stats['cache_enabled'] ? 'yes' : 'no'), OutputInterface::VERBOSITY_VERBOSE);

                    if ($stats['cache_enabled']) {
                        $output->writeln(sprintf('  Files parsed: %d', $stats['files_parsed']), OutputInterface::VERBOSITY_VERBOSE);
                        $output->writeln(sprintf('  Files from cache: %d', $stats['files_from_cache']), OutputInterface::VERBOSITY_VERBOSE);

                        if (isset($stats['tracked_files'])) {
                            $output->writeln(sprintf('  Total tracked files: %d', $stats['tracked_files']), OutputInterface::VERBOSITY_VERBOSE);
                        }

                        if (isset($stats['cache_age_seconds'])) {
                            $output->writeln(sprintf('  Cache age: %ds', $stats['cache_age_seconds']), OutputInterface::VERBOSITY_VERBOSE);
                        }

                        if ($stats['files_parsed'] > 0 && $stats['files_from_cache'] > 0) {
                            $totalFiles = $stats['files_parsed'] + $stats['files_from_cache'];
                            $cacheHitRate = ($stats['files_from_cache'] / $totalFiles) * 100;
                            $output->writeln(sprintf('  Cache hit rate: %.1f%%', $cacheHitRate), OutputInterface::VERBOSITY_VERBOSE);

                            // Estimate time saved
                            if ($parseDuration > 0 && $stats['files_parsed'] > 0) {
                                $timePerFile = $parseDuration / $stats['files_parsed'];
                                $timeSaved = $timePerFile * $stats['files_from_cache'];
                                $output->writeln(sprintf('  Estimated time saved: %.2fs', $timeSaved), OutputInterface::VERBOSITY_VERBOSE);
                            }
                        }
                    }
                }
            }

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
            if ($verbose) {
                $output->writeln('<error>Stack trace:</error>', OutputInterface::VERBOSITY_VERBOSE);
                $output->writeln($e->getTraceAsString(), OutputInterface::VERBOSITY_VERBOSE);
            }
            return Command::FAILURE;
        }
    }

    private function createStrategy(string $name): ?StrategyInterface
    {
        return match ($name) {
            'conservative' => new ConservativeStrategy(),
            'moderate' => new ModerateStrategy(),
            'minimal' => new MinimalStrategy(),
            default => null,
        };
    }

    private function createFormatter(string $format, string $projectRoot, ?string $testPattern): FormatterInterface
    {
        return match ($format) {
            'test' => new PhpUnitFormatter($projectRoot, $testPattern),
            'files' => new DefaultFormatter(),
        };
    }
}
