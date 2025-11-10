<?php

declare(strict_types=1);

namespace Diffalyzer\Formatter;

final class PsalmFormatter implements MethodAwareFormatterInterface
{
    public function __construct(
        private readonly array $classToFileMap = []
    ) {
    }

    public function format(array $files, bool $fullScan): string
    {
        if ($fullScan) {
            return '';
        }

        return implode(' ', $files);
    }

    public function formatMethods(array $methods, bool $fullScan): string
    {
        if ($fullScan) {
            return '';
        }

        $formatted = [];
        foreach ($methods as $method) {
            $formatted[] = $this->methodToFileMethod($method);
        }

        // Remove nulls (methods that couldn't be resolved)
        $formatted = array_filter($formatted);

        return implode(' ', $formatted);
    }

    /**
     * Convert fully qualified method name to file::method format
     *
     * @param string $fqMethodName e.g., "App\User::getName"
     * @return string|null e.g., "src/User.php::getName" or null if not resolvable
     */
    private function methodToFileMethod(string $fqMethodName): ?string
    {
        // Split class::method
        if (!str_contains($fqMethodName, '::')) {
            return null;
        }

        [$className, $methodName] = explode('::', $fqMethodName, 2);

        // Look up file for class
        if (!isset($this->classToFileMap[$className])) {
            return null;
        }

        $file = $this->classToFileMap[$className];

        return "{$file}::{$methodName}";
    }
}
