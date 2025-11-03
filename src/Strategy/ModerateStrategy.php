<?php

declare(strict_types=1);

namespace Diffalyzer\Strategy;

use Diffalyzer\Visitor\DependencyVisitor;

final class ModerateStrategy implements StrategyInterface
{
    public function extractDependencies(DependencyVisitor $visitor): array
    {
        return array_unique(array_merge(
            $visitor->getUses(),
            $visitor->getExtends(),
            $visitor->getImplements(),
            $visitor->getTraits()
        ));
    }
}
