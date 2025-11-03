<?php

declare(strict_types=1);

namespace Diffalyzer\Formatter;

final class PsalmFormatter implements FormatterInterface
{
    public function format(array $files, bool $fullScan): string
    {
        if ($fullScan) {
            return '';
        }

        return implode(' ', $files);
    }
}
