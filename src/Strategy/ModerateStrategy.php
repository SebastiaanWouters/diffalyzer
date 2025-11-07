<?php

declare(strict_types=1);

namespace Diffalyzer\Strategy;

use Diffalyzer\Visitor\DependencyVisitor;

final class ModerateStrategy implements StrategyInterface
{
    public function extractDependencies(DependencyVisitor $visitor): array
    {
        // Use array keys for O(1) deduplication across categories
        $dependencies = [];

        foreach ($visitor->getUses() as $class) {
            $dependencies[$class] = true;
        }
        foreach ($visitor->getExtends() as $class) {
            $dependencies[$class] = true;
        }
        foreach ($visitor->getImplements() as $class) {
            $dependencies[$class] = true;
        }
        foreach ($visitor->getTraits() as $class) {
            $dependencies[$class] = true;
        }

        return array_keys($dependencies);
    }
}
