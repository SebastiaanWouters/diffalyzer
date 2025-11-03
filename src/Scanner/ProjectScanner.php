<?php

declare(strict_types=1);

namespace Diffalyzer\Scanner;

use Symfony\Component\Finder\Finder;

final class ProjectScanner
{
    public function __construct(
        private readonly string $projectRoot
    ) {
    }

    public function getAllPhpFiles(): array
    {
        $finder = new Finder();
        $finder
            ->files()
            ->in($this->projectRoot)
            ->name('*.php')
            ->exclude(['vendor', 'node_modules', '.git', 'cache', 'var'])
            ->ignoreVCS(true)
            ->ignoreDotFiles(false);

        $files = [];
        foreach ($finder as $file) {
            $relativePath = str_replace($this->projectRoot . '/', '', $file->getRealPath());
            $files[] = $relativePath;
        }

        return $files;
    }
}
