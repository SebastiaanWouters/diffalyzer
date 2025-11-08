<?php

declare(strict_types=1);

namespace Diffalyzer\Parser;

/**
 * Interface for dependency parsers
 *
 * Allows switching between different parsing implementations
 * (AST-based with nikic/php-parser or token-based with token_get_all)
 */
interface ParserInterface
{
    /**
     * Parse PHP code and extract dependencies
     *
     * @param string $code PHP source code
     * @return ParseResult The parsed dependencies and declarations
     */
    public function parse(string $code): ParseResult;
}
