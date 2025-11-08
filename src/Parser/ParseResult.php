<?php

declare(strict_types=1);

namespace Diffalyzer\Parser;

/**
 * Result of parsing a PHP file for dependencies
 *
 * Value object containing all extracted dependency information
 */
final class ParseResult
{
    public function __construct(
        private readonly array $uses,
        private readonly array $extends,
        private readonly array $implements,
        private readonly array $traits,
        private readonly array $instantiations,
        private readonly array $staticCalls,
        private readonly array $methodCalls,
        private readonly array $declaredClasses
    ) {
    }

    public function getUses(): array
    {
        return $this->uses;
    }

    public function getExtends(): array
    {
        return $this->extends;
    }

    public function getImplements(): array
    {
        return $this->implements;
    }

    public function getTraits(): array
    {
        return $this->traits;
    }

    public function getInstantiations(): array
    {
        return $this->instantiations;
    }

    public function getStaticCalls(): array
    {
        return $this->staticCalls;
    }

    public function getMethodCalls(): array
    {
        return $this->methodCalls;
    }

    public function getDeclaredClasses(): array
    {
        return $this->declaredClasses;
    }
}
