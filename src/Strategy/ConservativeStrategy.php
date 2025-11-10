<?php

declare(strict_types=1);

namespace Diffalyzer\Strategy;

use Diffalyzer\Parser\ParseResult;
use Diffalyzer\Visitor\DependencyVisitor;

final class ConservativeStrategy implements StrategyInterface
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
        foreach ($visitor->getInstantiations() as $class) {
            $dependencies[$class] = true;
        }
        foreach ($visitor->getStaticCalls() as $class) {
            $dependencies[$class] = true;
        }

        return array_keys($dependencies);
    }

    public function extractDependenciesFromResult(ParseResult $result): array
    {
        // Use array keys for O(1) deduplication across categories
        $dependencies = [];

        foreach ($result->getUses() as $class) {
            $dependencies[$class] = true;
        }
        foreach ($result->getExtends() as $class) {
            $dependencies[$class] = true;
        }
        foreach ($result->getImplements() as $class) {
            $dependencies[$class] = true;
        }
        foreach ($result->getTraits() as $class) {
            $dependencies[$class] = true;
        }
        foreach ($result->getInstantiations() as $class) {
            $dependencies[$class] = true;
        }
        foreach ($result->getStaticCalls() as $class) {
            $dependencies[$class] = true;
        }
        // Note: includes are handled separately in DependencyAnalyzer
        // as they require file path resolution

        return array_keys($dependencies);
    }

    /**
     * Get raw include statements from ParseResult
     */
    public function extractIncludes(ParseResult $result): array
    {
        return $result->getIncludes();
    }
}
