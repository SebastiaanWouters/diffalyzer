<?php

declare(strict_types=1);

namespace Diffalyzer\Tests\Cache;

use Diffalyzer\Cache\CacheInvalidator;
use PHPUnit\Framework\TestCase;

final class CacheInvalidatorTest extends TestCase
{
    private CacheInvalidator $invalidator;

    protected function setUp(): void
    {
        $this->invalidator = new CacheInvalidator();
    }

    public function testGetFilesToReparseWithNoChanges(): void
    {
        $changedFiles = [];
        $dependencyGraph = ['file1.php' => ['ClassA']];
        $reverseDependencyGraph = ['file1.php' => ['file2.php']];

        $toReparse = $this->invalidator->getFilesToReparse(
            $changedFiles,
            $dependencyGraph,
            $reverseDependencyGraph
        );

        $this->assertEmpty($toReparse);
    }

    public function testGetFilesToReparseWithChangedFile(): void
    {
        $changedFiles = ['file1.php'];
        $dependencyGraph = ['file1.php' => ['ClassA'], 'file2.php' => ['ClassB']];
        $reverseDependencyGraph = ['file1.php' => ['file2.php', 'file3.php']];

        $toReparse = $this->invalidator->getFilesToReparse(
            $changedFiles,
            $dependencyGraph,
            $reverseDependencyGraph
        );

        $this->assertContains('file1.php', $toReparse);
        $this->assertContains('file2.php', $toReparse);
        $this->assertContains('file3.php', $toReparse);
    }

    public function testGetFilesToReparseWithTransitiveDependencies(): void
    {
        $changedFiles = ['file1.php'];
        $dependencyGraph = [
            'file1.php' => ['ClassA'],
            'file2.php' => ['ClassB'],
            'file3.php' => ['ClassC'],
        ];
        $reverseDependencyGraph = [
            'file1.php' => ['file2.php'],
            'file2.php' => ['file3.php'],
        ];

        $toReparse = $this->invalidator->getFilesToReparse(
            $changedFiles,
            $dependencyGraph,
            $reverseDependencyGraph
        );

        $this->assertContains('file1.php', $toReparse);
        $this->assertContains('file2.php', $toReparse);
        $this->assertContains('file3.php', $toReparse);
    }

    public function testRemoveDeletedFiles(): void
    {
        $currentFiles = ['file1.php', 'file2.php'];
        $dependencyGraph = [
            'file1.php' => ['ClassA'],
            'file2.php' => ['ClassB'],
            'file3.php' => ['ClassC'], // This file was deleted
        ];
        $classToFileMap = [
            'ClassA' => 'file1.php',
            'ClassB' => 'file2.php',
            'ClassC' => 'file3.php', // This file was deleted
        ];

        $cleaned = $this->invalidator->removeDeletedFiles(
            $currentFiles,
            $dependencyGraph,
            $classToFileMap
        );

        $this->assertArrayHasKey('file1.php', $cleaned['dependencyGraph']);
        $this->assertArrayHasKey('file2.php', $cleaned['dependencyGraph']);
        $this->assertArrayNotHasKey('file3.php', $cleaned['dependencyGraph']);

        $this->assertArrayHasKey('ClassA', $cleaned['classToFileMap']);
        $this->assertArrayHasKey('ClassB', $cleaned['classToFileMap']);
        $this->assertArrayNotHasKey('ClassC', $cleaned['classToFileMap']);
    }

    public function testGetIndirectlyAffectedFiles(): void
    {
        $changedFiles = ['file1.php'];
        $classToFileMap = [
            'ClassA' => 'file1.php',
            'ClassB' => 'file2.php',
        ];
        $dependencyGraph = [
            'file1.php' => ['ClassB'],
            'file2.php' => ['ClassA'], // file2 depends on ClassA from file1
            'file3.php' => ['ClassA'], // file3 also depends on ClassA from file1
        ];

        $affected = $this->invalidator->getIndirectlyAffectedFiles(
            $changedFiles,
            $classToFileMap,
            $dependencyGraph
        );

        $this->assertContains('file2.php', $affected);
        $this->assertContains('file3.php', $affected);
    }

    public function testGetFilesToReparseHandlesCycles(): void
    {
        $changedFiles = ['file1.php'];
        $dependencyGraph = [
            'file1.php' => ['ClassA'],
            'file2.php' => ['ClassB'],
        ];
        // Circular dependency: file1 -> file2 -> file1
        $reverseDependencyGraph = [
            'file1.php' => ['file2.php'],
            'file2.php' => ['file1.php'],
        ];

        $toReparse = $this->invalidator->getFilesToReparse(
            $changedFiles,
            $dependencyGraph,
            $reverseDependencyGraph
        );

        // Should handle the cycle gracefully
        $this->assertContains('file1.php', $toReparse);
        $this->assertContains('file2.php', $toReparse);
        $this->assertCount(2, $toReparse);
    }
}
