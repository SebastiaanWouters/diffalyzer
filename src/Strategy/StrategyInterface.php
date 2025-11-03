<?php

declare(strict_types=1);

namespace Diffalyzer\Strategy;

use Diffalyzer\Visitor\DependencyVisitor;

interface StrategyInterface
{
    public function extractDependencies(DependencyVisitor $visitor): array;
}
