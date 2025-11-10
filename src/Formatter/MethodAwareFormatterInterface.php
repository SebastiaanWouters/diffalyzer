<?php

declare(strict_types=1);

namespace Diffalyzer\Formatter;

/**
 * Interface for formatters that support method-level granularity
 *
 * Formatters implementing this interface can output specific methods
 * in addition to whole files.
 */
interface MethodAwareFormatterInterface extends FormatterInterface
{
    /**
     * Format affected methods for output
     *
     * @param array $methods Array of fully qualified method names (e.g., "App\User::getName")
     * @param bool $fullScan Whether this is a full scan
     * @return string Formatted output (e.g., "src/User.php::getName tests/UserTest.php::testGetName")
     */
    public function formatMethods(array $methods, bool $fullScan): string;
}
