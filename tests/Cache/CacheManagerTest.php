<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\Cache;

use Diffalyzer\Cache\CacheManager;
use Diffalyzer\Cache\FileHashRegistry;
use PHPUnit\Framework\TestCase;

final class CacheManagerTest extends TestCase
{
    private string $tempDir;
    private CacheManager $cacheManager;
    private FileHashRegistry $registry;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/diffalyzer_cache_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->registry = new FileHashRegistry($this->tempDir);
        $this->cacheManager = new CacheManager($this->tempDir, $this->registry);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testInitialize(): void
    {
        $this->assertTrue($this->cacheManager->initialize());
        $this->assertDirectoryExists($this->cacheManager->getCacheDir());
    }

    public function testExistsReturnsFalseWhenNoCacheExists(): void
    {
        $this->assertFalse($this->cacheManager->exists());
    }

    public function testSaveAndLoadGraph(): void
    {
        $graphData = [
            'dependencyGraph' => ['file1.php' => ['ClassA', 'ClassB']],
            'classToFileMap' => ['ClassA' => 'file1.php', 'ClassB' => 'file2.php'],
            'reverseDependencyGraph' => ['file1.php' => ['file3.php']],
        ];

        $this->assertTrue($this->cacheManager->saveGraph($graphData));

        $loaded = $this->cacheManager->loadGraph();
        $this->assertNotNull($loaded);
        $this->assertEquals($graphData, $loaded);
    }

    public function testLoadGraphReturnsNullWhenNoCache(): void
    {
        $this->assertNull($this->cacheManager->loadGraph());
    }

    public function testClear(): void
    {
        $graphData = [
            'dependencyGraph' => ['file1.php' => []],
            'classToFileMap' => [],
            'reverseDependencyGraph' => [],
        ];

        $this->cacheManager->saveGraph($graphData);

        // Verify graph was saved
        $this->assertNotNull($this->cacheManager->loadGraph());

        $this->assertTrue($this->cacheManager->clear());

        // Verify graph was cleared
        $this->assertNull($this->cacheManager->loadGraph());
    }

    public function testGetStats(): void
    {
        $stats = $this->cacheManager->getStats();

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('exists', $stats);
        $this->assertArrayHasKey('cache_dir', $stats);
        $this->assertArrayHasKey('tracked_files', $stats);
        $this->assertFalse($stats['exists']);
    }

    public function testGetStatsWithCache(): void
    {
        $graphData = [
            'dependencyGraph' => ['file1.php' => []],
            'classToFileMap' => [],
            'reverseDependencyGraph' => [],
        ];

        $this->cacheManager->saveGraph($graphData);

        // Also save registry to make exists() return true
        file_put_contents($this->tempDir . '/test.php', '<?php echo "test";');
        $this->registry->updateFile('test.php');
        $this->cacheManager->saveRegistry();

        $stats = $this->cacheManager->getStats();

        $this->assertTrue($stats['exists']);
        $this->assertArrayHasKey('cache_timestamp', $stats);
        $this->assertArrayHasKey('cache_age_seconds', $stats);
        $this->assertArrayHasKey('cache_size_bytes', $stats);
        $this->assertGreaterThan(0, $stats['cache_size_bytes']);
    }

    public function testSaveAndLoadRegistry(): void
    {
        // Create actual files
        file_put_contents($this->tempDir . '/file1.php', '<?php echo "1";');
        file_put_contents($this->tempDir . '/file2.php', '<?php echo "2";');

        $this->registry->updateFiles(['file1.php', 'file2.php']);

        $this->assertTrue($this->cacheManager->saveRegistry());

        $newRegistry = new FileHashRegistry($this->tempDir);
        $newManager = new CacheManager($this->tempDir, $newRegistry);

        $this->assertTrue($newManager->loadRegistry());
        $this->assertEquals(2, $newRegistry->count());
    }
}
