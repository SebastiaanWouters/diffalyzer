<?php

declare(strict_types=1);

namespace Diffalyzer\Parser;

use Diffalyzer\Visitor\DependencyVisitor;
use PhpParser\Error;
use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * AST-based parser implementation using nikic/php-parser
 *
 * Slower but proven implementation used as the default and reference
 */
final class AstBasedParser implements ParserInterface
{
    private Parser $parser;
    private NodeTraverser $traverser;

    public function __construct()
    {
        $this->parser = (new ParserFactory())->createForNewestSupportedVersion();
        $this->traverser = new NodeTraverser();
    }

    public function parse(string $code): ParseResult
    {
        try {
            $ast = $this->parser->parse($code);
            if ($ast === null) {
                return $this->emptyResult();
            }

            $visitor = new DependencyVisitor();
            $this->traverser->addVisitor($visitor);
            $this->traverser->traverse($ast);
            $this->traverser->removeVisitor($visitor);

            return new ParseResult(
                uses: $visitor->getUses(),
                extends: $visitor->getExtends(),
                implements: $visitor->getImplements(),
                traits: $visitor->getTraits(),
                instantiations: $visitor->getInstantiations(),
                staticCalls: $visitor->getStaticCalls(),
                methodCalls: $visitor->getMethodCalls(),
                declaredClasses: $visitor->getDeclaredClasses()
            );
        } catch (Error $error) {
            // Parse error - return empty result
            return $this->emptyResult();
        }
    }

    private function emptyResult(): ParseResult
    {
        return new ParseResult(
            uses: [],
            extends: [],
            implements: [],
            traits: [],
            instantiations: [],
            staticCalls: [],
            methodCalls: [],
            declaredClasses: []
        );
    }
}
