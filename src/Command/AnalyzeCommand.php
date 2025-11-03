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

        if ($staged && ($from !== null || $to !== null)) {
            $output->writeln('<error>Cannot use --staged with --from or --to</error>');
            return Command::FAILURE;
        }

        if ($fullScanPattern !== null && @preg_match($fullScanPattern, '') === false) {
            $output->writeln('<error>Invalid regex pattern</error>');
            return Command::FAILURE;
        }

        try {
            $changeDetector = new ChangeDetector($projectRoot);
            $allChangedFiles = $changeDetector->getAllChangedFiles($from, $to, $staged);

            $fullScanMatcher = new FullScanMatcher();
            $shouldFullScan = $fullScanMatcher->shouldTriggerFullScan($allChangedFiles, $fullScanPattern);

            $changedFiles = array_filter($allChangedFiles, fn(string $file): bool => str_ends_with($file, '.php'));

            if (empty($changedFiles) && !$shouldFullScan) {
                $output->write('');
                return Command::SUCCESS;
            }

            $formatter = $this->createFormatter($outputFormat, $projectRoot);
            $affectedFiles = [];

            if ($shouldFullScan) {
                $output->write($formatter->format([], true));
                return Command::SUCCESS;
            }

            $scanner = new ProjectScanner($projectRoot);
            $allPhpFiles = $scanner->getAllPhpFiles();

            $analyzer = new DependencyAnalyzer($projectRoot, $strategy);
            $analyzer->buildDependencyGraph($allPhpFiles);
            $affectedFiles = $analyzer->getAffectedFiles($changedFiles);

            $result = $formatter->format($affectedFiles, false);
            $output->write($result);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $output->writeln('<error>Error: ' . $e->getMessage() . '</error>');
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

    private function createFormatter(string $format, string $projectRoot): FormatterInterface
    {
        return match ($format) {
            'test' => new PhpUnitFormatter($projectRoot),
            'files' => new DefaultFormatter(),
        };
    }
}
