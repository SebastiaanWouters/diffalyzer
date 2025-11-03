<?php

declare(strict_types=1);

namespace Diffalyzer\Matcher;

final class FullScanMatcher
{
    private const BUILT_IN_PATTERNS = [
        '/composer\.(json|lock)$/',
    ];

    public function shouldTriggerFullScan(array $changedFiles, ?string $userPattern): bool
    {
        $patterns = self::BUILT_IN_PATTERNS;

        if ($userPattern !== null && $userPattern !== '') {
            $patterns[] = $userPattern;
        }

        foreach ($changedFiles as $file) {
            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $file) === 1) {
                    return true;
                }
            }
        }

        return false;
    }
}
