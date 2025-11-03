<?php

declare(strict_types=1);

namespace Diffalyzer\Formatter;

interface FormatterInterface
{
    public function format(array $files, bool $fullScan): string;
}
