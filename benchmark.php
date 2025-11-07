#!/usr/bin/env php
<?php
/**
 * Performance Benchmark Script
 *
 * Measures the performance improvements from the optimization work.
 * Run this script to compare cold cache vs warm cache performance.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Diffalyzer\Analyzer\DependencyAnalyzer;
use Diffalyzer\Scanner\ProjectScanner;
use Diffalyzer\Strategy\ConservativeStrategy;

function formatTime(float $seconds): string
{
    if ($seconds < 0.001) {
        return sprintf('%.2fμs', $seconds * 1000000);
    } elseif ($seconds < 1) {
        return sprintf('%.2fms', $seconds * 1000);
    } else {
        return sprintf('%.3fs', $seconds);
    }
}

function runBenchmark(string $label, callable $operation): array
{
    $iterations = 3;
    $times = [];

    for ($i = 0; $i < $iterations; $i++) {
        $start = microtime(true);
        $operation();
        $duration = microtime(true) - $start;
        $times[] = $duration;
    }

    $avg = array_sum($times) / count($times);
    $min = min($times);
    $max = max($times);

    return [
        'label' => $label,
        'avg' => $avg,
        'min' => $min,
        'max' => $max,
    ];
}

function printResults(array $results): void
{
    echo "\n┌" . str_repeat('─', 78) . "┐\n";
    echo "│" . str_pad(' PERFORMANCE BENCHMARK RESULTS', 78) . "│\n";
    echo "├" . str_repeat('─', 78) . "┤\n";

    foreach ($results as $result) {
        $label = str_pad($result['label'], 40);
        $avg = str_pad(formatTime($result['avg']), 12);
        $range = sprintf('%s - %s', formatTime($result['min']), formatTime($result['max']));

        echo "│ $label │ Avg: $avg │ Range: " . str_pad($range, 18) . "│\n";
    }

    echo "└" . str_repeat('─', 78) . "┘\n\n";
}

echo "\n🚀 Diffalyzer Performance Benchmark\n";
echo "═══════════════════════════════════\n\n";

$projectRoot = getcwd();
$strategy = new ConservativeStrategy();

// Scan for PHP files
echo "📊 Scanning project for PHP files...\n";
$scanner = new ProjectScanner($projectRoot);
$phpFiles = $scanner->getAllPhpFiles();
$fileCount = count($phpFiles);

echo "   Found {$fileCount} PHP files\n\n";

if ($fileCount === 0) {
    echo "❌ No PHP files found in project. Cannot run benchmark.\n";
    exit(1);
}

$results = [];

// Benchmark 1: Cold cache (full parse)
echo "🔥 Test 1: Cold Cache Performance (Full Parse)\n";
echo "   Clearing cache and parsing all files...\n";

$result = runBenchmark('Cold Cache (Full Parse)', function () use ($projectRoot, $strategy, $phpFiles) {
    $analyzer = new DependencyAnalyzer($projectRoot, $strategy);
    $analyzer->clearCache();
    $analyzer->buildDependencyGraph($phpFiles);
});
$results[] = $result;

echo "   ✓ Completed: " . formatTime($result['avg']) . " (avg)\n\n";

// Benchmark 2: Warm cache (no changes)
echo "❄️  Test 2: Warm Cache Performance (No Changes)\n";
echo "   Using cached dependency graph...\n";

$result = runBenchmark('Warm Cache (Perfect Hit)', function () use ($projectRoot, $strategy, $phpFiles) {
    $analyzer = new DependencyAnalyzer($projectRoot, $strategy);
    $analyzer->buildDependencyGraph($phpFiles);
});
$results[] = $result;

echo "   ✓ Completed: " . formatTime($result['avg']) . " (avg)\n\n";

// Benchmark 3: Cache disabled
echo "🚫 Test 3: Cache Disabled (Always Full Parse)\n";
echo "   Parsing without cache...\n";

$result = runBenchmark('No Cache (Full Parse)', function () use ($projectRoot, $strategy, $phpFiles) {
    $analyzer = new DependencyAnalyzer($projectRoot, $strategy);
    $analyzer->setCacheEnabled(false);
    $analyzer->buildDependencyGraph($phpFiles);
});
$results[] = $result;

echo "   ✓ Completed: " . formatTime($result['avg']) . " (avg)\n\n";

// Benchmark 4: Project scan
echo "📁 Test 4: Project Scan Performance\n";
echo "   Scanning filesystem for PHP files...\n";

$result = runBenchmark('Project File Scan', function () use ($projectRoot) {
    $scanner = new ProjectScanner($projectRoot);
    $scanner->getAllPhpFiles();
});
$results[] = $result;

echo "   ✓ Completed: " . formatTime($result['avg']) . " (avg)\n\n";

// Get cache stats
$analyzer = new DependencyAnalyzer($projectRoot, $strategy);
$analyzer->buildDependencyGraph($phpFiles);
$stats = $analyzer->getCacheStats();

// Print detailed results
printResults($results);

// Print cache statistics
echo "📈 Cache Statistics:\n";
echo "═══════════════════\n";
echo "  Files parsed: {$stats['files_parsed']}\n";
echo "  Files from cache: {$stats['files_from_cache']}\n";
echo "  Total tracked files: {$stats['tracked_files']}\n";

if (isset($stats['cache_age_seconds'])) {
    $age = $stats['cache_age_seconds'];
    $ageStr = $age < 60 ? "{$age}s" : sprintf("%.1f minutes", $age / 60);
    echo "  Cache age: $ageStr\n";
}

if (isset($stats['cache_size_bytes'])) {
    $size = $stats['cache_size_bytes'];
    $sizeStr = $size < 1024 ? "{$size}B" : sprintf("%.2fKB", $size / 1024);
    echo "  Cache size: $sizeStr\n";
}

// Calculate speedup
$coldTime = $results[0]['avg'];
$warmTime = $results[1]['avg'];
$speedup = $coldTime / $warmTime;

echo "\n💡 Performance Summary:\n";
echo "═════════════════════\n";
printf("  Warm cache is %.1fx faster than cold cache\n", $speedup);

if ($fileCount >= 100) {
    echo "  ✅ Parallel parsing enabled (100+ files)\n";
} else {
    echo "  ℹ️  Sequential parsing used (<100 files)\n";
    echo "     Note: Parallel parsing activates with 100+ files\n";
}

echo "\n✨ Optimizations Applied:\n";
echo "════════════════════════\n";
echo "  ✓ Removed wasteful hash computation\n";
echo "  ✓ Optimized JSON serialization\n";
echo "  ✓ Eliminated redundant array_unique calls\n";
echo "  ✓ Optimized dependency collection with array keys\n";
echo "  ✓ Integrated parallel parsing for large projects\n";

echo "\n🎯 Benchmark complete!\n\n";
