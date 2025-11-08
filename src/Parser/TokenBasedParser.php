<?php

declare(strict_types=1);

namespace Diffalyzer\Parser;

/**
 * Token-based parser implementation (5-10x faster than AST)
 *
 * Uses PHP's built-in token_get_all() for fast dependency extraction
 */
final class TokenBasedParser implements ParserInterface
{
    private TokenBasedDependencyExtractor $extractor;

    public function __construct()
    {
        $this->extractor = new TokenBasedDependencyExtractor();
    }

    public function parse(string $code): ParseResult
    {
        $this->extractor->extract($code);

        return new ParseResult(
            uses: $this->extractor->getUses(),
            extends: $this->extractor->getExtends(),
            implements: $this->extractor->getImplements(),
            traits: $this->extractor->getTraits(),
            instantiations: $this->extractor->getInstantiations(),
            staticCalls: $this->extractor->getStaticCalls(),
            methodCalls: $this->extractor->getMethodCalls(),
            declaredClasses: $this->extractor->getDeclaredClasses()
        );
    }
}
