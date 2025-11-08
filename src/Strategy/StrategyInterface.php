<?php

declare(strict_types=1);

namespace Diffalyzer\Strategy;

use Diffalyzer\Parser\ParseResult;
use Diffalyzer\Visitor\DependencyVisitor;

interface StrategyInterface
{
    /**
     * Extract dependencies from DependencyVisitor (for backward compatibility with parallel parser)
     */
    public function extractDependencies(DependencyVisitor $visitor): array;

    /**
     * Extract dependencies from ParseResult (for new parser interface)
     */
    public function extractDependenciesFromResult(ParseResult $result): array;
}
