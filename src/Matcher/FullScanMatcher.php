<?php

declare(strict_types=1);

namespace Diffalyzer\Matcher;

final class FullScanMatcher
{
    public function shouldTriggerFullScan(array $changedFiles, ?string $pattern): bool
    {
        if ($pattern === null || $pattern === '') {
            return false;
        }

        foreach ($changedFiles as $file) {
            if (preg_match($pattern, $file) === 1) {
                return true;
            }
        }

        return false;
    }
}
