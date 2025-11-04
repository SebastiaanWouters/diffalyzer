<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\Cache;

use Diffalyzer\Cache\FileHashRegistry;
use PHPUnit\Framework\TestCase;

final class FileHashRegistryTest extends TestCase
{
    private string $tempDir;
    private FileHashRegistry $registry;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/diffalyzer_registry_test_' . uniqid();
        mkdir($this->tempDir, 0777, true);
        $this->registry = new FileHashRegistry($this->tempDir);
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

    private function createTestFile(string $name, string $content): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, $content);
        return $name;
    }

    public function testUpdateAndLoadFile(): void
    {
        $file = $this->createTestFile('test.php', '<?php echo "test";');

        $this->registry->updateFile($file);
        $this->assertTrue($this->registry->has($file));

        $info = $this->registry->get($file);
        $this->assertNotNull($info);
        $this->assertArrayHasKey('mtime', $info);
        $this->assertArrayHasKey('size', $info);
        $this->assertArrayHasKey('hash', $info);
    }

    public function testHasChangedForNewFile(): void
    {
        $file = $this->createTestFile('new.php', '<?php echo "new";');

        $this->assertTrue($this->registry->hasChanged($file));
    }

    public function testHasChangedForUnchangedFile(): void
    {
        $file = $this->createTestFile('unchanged.php', '<?php echo "unchanged";');

        $this->registry->updateFile($file);
        $this->assertFalse($this->registry->hasChanged($file));
    }

    public function testHasChangedForModifiedFile(): void
    {
        $file = $this->createTestFile('modified.php', '<?php echo "original";');

        $this->registry->updateFile($file);

        // Modify the file
        usleep(100000); // Wait 100ms to ensure mtime changes
        file_put_contents($this->tempDir . '/' . $file, '<?php echo "modified content";');
        clearstatcache(); // Clear filesystem cache

        $this->assertTrue($this->registry->hasChanged($file));
    }

    public function testHasChangedForDeletedFile(): void
    {
        $file = $this->createTestFile('deleted.php', '<?php echo "deleted";');

        $this->registry->updateFile($file);
        unlink($this->tempDir . '/' . $file);

        $this->assertTrue($this->registry->hasChanged($file));
    }

    public function testSaveAndLoadRegistry(): void
    {
        $file1 = $this->createTestFile('file1.php', '<?php echo "1";');
        $file2 = $this->createTestFile('file2.php', '<?php echo "2";');

        $this->registry->updateFile($file1);
        $this->registry->updateFile($file2);

        $cacheFile = $this->tempDir . '/registry.json';
        $this->registry->save($cacheFile);

        // Create new registry and load
        $newRegistry = new FileHashRegistry($this->tempDir);
        $this->assertTrue($newRegistry->load($cacheFile));
        $this->assertTrue($newRegistry->has($file1));
        $this->assertTrue($newRegistry->has($file2));
    }

    public function testGetChangedFiles(): void
    {
        $file1 = $this->createTestFile('file1.php', '<?php echo "1";');
        $file2 = $this->createTestFile('file2.php', '<?php echo "2";');
        $file3 = $this->createTestFile('file3.php', '<?php echo "3";');

        $this->registry->updateFile($file1);
        $this->registry->updateFile($file2);

        // Modify file2
        sleep(1);
        file_put_contents($this->tempDir . '/' . $file2, '<?php echo "2 modified";');

        $currentFiles = [$file1, $file2, $file3];
        $changedFiles = $this->registry->getChangedFiles($currentFiles);

        $this->assertContains($file2, $changedFiles); // Modified
        $this->assertContains($file3, $changedFiles); // New
        $this->assertNotContains($file1, $changedFiles); // Unchanged
    }

    public function testClearRegistry(): void
    {
        $file = $this->createTestFile('clear.php', '<?php echo "clear";');

        $this->registry->updateFile($file);
        $this->assertTrue($this->registry->has($file));

        $this->registry->clear();
        $this->assertFalse($this->registry->has($file));
        $this->assertEquals(0, $this->registry->count());
    }

    public function testCount(): void
    {
        $this->assertEquals(0, $this->registry->count());

        $file1 = $this->createTestFile('count1.php', '<?php echo "1";');
        $file2 = $this->createTestFile('count2.php', '<?php echo "2";');

        $this->registry->updateFile($file1);
        $this->assertEquals(1, $this->registry->count());

        $this->registry->updateFile($file2);
        $this->assertEquals(2, $this->registry->count());
    }
}
