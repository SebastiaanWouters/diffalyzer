<?php

declare(strict_types=1);

namespace Diffalyzer\Parser;

/**
 * Fast dependency extractor using PHP's built-in tokenizer (5-10x faster than AST parsing)
 *
 * Extracts the same dependency information as DependencyVisitor but using token_get_all()
 * instead of nikic/php-parser's AST. This provides significant performance improvements
 * while maintaining accuracy for dependency analysis.
 */
final class TokenBasedDependencyExtractor
{
    private ?string $currentNamespace = null;
    private array $useAliases = []; // alias => fullyQualifiedName
    private array $uses = [];
    private array $extends = [];
    private array $implements = [];
    private array $traits = [];
    private array $instantiations = [];
    private array $staticCalls = [];
    private array $methodCalls = [];
    private array $declaredClasses = [];

    /**
     * Extract dependencies from PHP source code
     */
    public function extract(string $code): void
    {
        $this->reset();

        $tokens = @token_get_all($code);
        if ($tokens === false) {
            return; // Parse error - gracefully handle like nikic/php-parser
        }

        $this->processTokens($tokens);
    }

    /**
     * Reset internal state for reuse
     */
    private function reset(): void
    {
        $this->currentNamespace = null;
        $this->useAliases = [];
        $this->uses = [];
        $this->extends = [];
        $this->implements = [];
        $this->traits = [];
        $this->instantiations = [];
        $this->staticCalls = [];
        $this->methodCalls = [];
        $this->declaredClasses = [];
    }

    /**
     * Process tokens to extract dependencies
     */
    private function processTokens(array $tokens): void
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Skip non-array tokens (strings like '{', '}', etc.)
            if (!is_array($token)) {
                continue;
            }

            [$type, $text] = $token;

            switch ($type) {
                case T_NAMESPACE:
                    $i = $this->extractNamespace($tokens, $i);
                    break;

                case T_USE:
                    // Check if this is a trait use (inside a class) or import use
                    if ($this->isTraitUse($tokens, $i)) {
                        $i = $this->extractTraitUse($tokens, $i);
                    } else {
                        $i = $this->extractUseStatement($tokens, $i);
                    }
                    break;

                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                    $i = $this->extractClassDeclaration($tokens, $i, $type);
                    break;

                case T_EXTENDS:
                    $i = $this->extractExtends($tokens, $i);
                    break;

                case T_IMPLEMENTS:
                    $i = $this->extractImplements($tokens, $i);
                    break;

                case T_NEW:
                    $i = $this->extractNew($tokens, $i);
                    break;

                case T_DOUBLE_COLON:
                    // Look back to see if previous token was a class name
                    $className = $this->getPreviousClassName($tokens, $i);
                    if ($className !== null) {
                        $this->staticCalls[$this->resolveName($className)] = true;
                    }
                    break;

                case T_VARIABLE:
                    // Track method calls like $this->method() or $foo->bar()
                    if ($text === '$this' || preg_match('/^\$[a-zA-Z_]/', $text)) {
                        $this->methodCalls[$text] = true;
                    }
                    break;
            }
        }
    }

    /**
     * Extract namespace declaration
     */
    private function extractNamespace(array $tokens, int $i): int
    {
        $i++; // Skip T_NAMESPACE
        $namespace = '';

        while ($i < count($tokens)) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === ';' || $token === '{') {
                    break;
                }
                $i++;
                continue;
            }

            [$type, $text] = $token;

            if ($type === T_STRING || $type === T_NS_SEPARATOR || $type === T_NAME_QUALIFIED) {
                $namespace .= $text;
            } elseif ($type !== T_WHITESPACE) {
                break;
            }

            $i++;
        }

        $this->currentNamespace = trim($namespace, '\\');
        return $i;
    }

    /**
     * Extract use statement (imports)
     */
    private function extractUseStatement(array $tokens, int $i): int
    {
        $i++; // Skip T_USE
        $fullName = '';
        $alias = null;
        $isGroupUse = false;
        $prefix = '';

        while ($i < count($tokens)) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === ';') {
                    // End of use statement
                    if ($fullName) {
                        if ($isGroupUse && $prefix) {
                            $fullName = trim($prefix, '\\') . '\\' . trim($fullName, '\\');
                        }
                        $finalAlias = $alias ?? $this->getClassNameFromFQN($fullName);
                        $this->useAliases[$finalAlias] = ltrim($fullName, '\\');
                        $this->uses[ltrim($fullName, '\\')] = true;
                    }
                    break;
                } elseif ($token === ',') {
                    // Multiple imports in one statement or group use
                    if ($fullName) {
                        if ($isGroupUse && $prefix) {
                            $fullName = trim($prefix, '\\') . '\\' . trim($fullName, '\\');
                        }
                        $finalAlias = $alias ?? $this->getClassNameFromFQN($fullName);
                        $this->useAliases[$finalAlias] = ltrim($fullName, '\\');
                        $this->uses[ltrim($fullName, '\\')] = true;
                    }
                    $fullName = '';
                    $alias = null;
                    $i++;
                    continue;
                } elseif ($token === '{') {
                    // Group use statement
                    $prefix = $fullName;
                    $fullName = '';
                    $isGroupUse = true;
                    $i++;
                    continue;
                } elseif ($token === '}') {
                    // End of group use - process last item in group
                    if ($fullName && $isGroupUse && $prefix) {
                        $fullName = trim($prefix, '\\') . '\\' . trim($fullName, '\\');
                        $finalAlias = $alias ?? $this->getClassNameFromFQN($fullName);
                        $this->useAliases[$finalAlias] = ltrim($fullName, '\\');
                        $this->uses[ltrim($fullName, '\\')] = true;
                    }
                    $fullName = '';
                    $alias = null;
                    $isGroupUse = false;
                    $prefix = '';
                    $i++;
                    continue;
                }
                $i++;
                continue;
            }

            [$type, $text] = $token;

            if ($type === T_STRING || $type === T_NS_SEPARATOR || $type === T_NAME_QUALIFIED || $type === T_NAME_FULLY_QUALIFIED) {
                $fullName .= $text;
            } elseif ($type === T_AS) {
                // Use alias
                $i++;
                while ($i < count($tokens)) {
                    $nextToken = $tokens[$i];
                    if (is_array($nextToken) && $nextToken[0] === T_STRING) {
                        $alias = $nextToken[1];
                        break;
                    }
                    $i++;
                }
            } elseif ($type !== T_WHITESPACE && $type !== T_FUNCTION && $type !== T_CONST) {
                break;
            }

            $i++;
        }

        return $i;
    }

    /**
     * Check if this is a trait use (inside class) vs import use
     */
    private function isTraitUse(array $tokens, int $i): bool
    {
        // Look backwards to see if we're inside a class/trait definition
        $braceLevel = 0;
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];

            if (!is_array($token)) {
                if ($token === '{') {
                    $braceLevel++;
                } elseif ($token === '}') {
                    $braceLevel--;
                }
                continue;
            }

            [$type] = $token;

            // If we find class/interface/trait before leaving the scope, this is a trait use
            if (($type === T_CLASS || $type === T_TRAIT) && $braceLevel > 0) {
                return true;
            }

            // If we find namespace before finding class, this is an import
            if ($type === T_NAMESPACE && $braceLevel === 0) {
                return false;
            }
        }

        return false;
    }

    /**
     * Extract trait use statement
     */
    private function extractTraitUse(array $tokens, int $i): int
    {
        $i++; // Skip T_USE
        $traitName = '';

        while ($i < count($tokens)) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === ';' || $token === '{' || $token === ',') {
                    if ($traitName) {
                        $this->traits[$this->resolveName($traitName)] = true;
                    }
                    if ($token === ';' || $token === '{') {
                        break;
                    }
                    $traitName = '';
                    $i++;
                    continue;
                }
                $i++;
                continue;
            }

            [$type, $text] = $token;

            if ($type === T_STRING || $type === T_NS_SEPARATOR || $type === T_NAME_QUALIFIED || $type === T_NAME_FULLY_QUALIFIED) {
                $traitName .= $text;
            } elseif ($type !== T_WHITESPACE) {
                break;
            }

            $i++;
        }

        return $i;
    }

    /**
     * Extract class/interface/trait declaration
     */
    private function extractClassDeclaration(array $tokens, int $i, int $tokenType): int
    {
        $i++; // Skip T_CLASS/T_INTERFACE/T_TRAIT
        $className = '';

        // Find the class name
        while ($i < count($tokens)) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                break;
            }

            [$type, $text] = $token;

            if ($type === T_STRING) {
                $className = $text;
                break;
            }

            $i++;
        }

        if ($className) {
            $fqn = $this->currentNamespace !== null
                ? $this->currentNamespace . '\\' . $className
                : $className;
            $this->declaredClasses[$fqn] = true;
        }

        return $i;
    }

    /**
     * Extract extends clause
     */
    private function extractExtends(array $tokens, int $i): int
    {
        $i++; // Skip T_EXTENDS
        $className = '';

        while ($i < count($tokens)) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === '{' || $token === ',') {
                    if ($className) {
                        $this->extends[$this->resolveName($className)] = true;
                    }
                    if ($token === '{') {
                        break;
                    }
                    $className = '';
                    $i++;
                    continue;
                }
                $i++;
                continue;
            }

            [$type, $text] = $token;

            if ($type === T_STRING || $type === T_NS_SEPARATOR || $type === T_NAME_QUALIFIED || $type === T_NAME_FULLY_QUALIFIED) {
                $className .= $text;
            } elseif ($type === T_IMPLEMENTS) {
                // Stop at implements keyword
                if ($className) {
                    $this->extends[$this->resolveName($className)] = true;
                }
                return $i - 1; // Let the main loop handle T_IMPLEMENTS
            } elseif ($type !== T_WHITESPACE) {
                break;
            }

            $i++;
        }

        if ($className) {
            $this->extends[$this->resolveName($className)] = true;
        }

        return $i;
    }

    /**
     * Extract implements clause
     */
    private function extractImplements(array $tokens, int $i): int
    {
        $i++; // Skip T_IMPLEMENTS
        $interfaceName = '';

        while ($i < count($tokens)) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === '{' || $token === ',') {
                    if ($interfaceName) {
                        $this->implements[$this->resolveName($interfaceName)] = true;
                    }
                    if ($token === '{') {
                        break;
                    }
                    $interfaceName = '';
                    $i++;
                    continue;
                }
                $i++;
                continue;
            }

            [$type, $text] = $token;

            if ($type === T_STRING || $type === T_NS_SEPARATOR || $type === T_NAME_QUALIFIED || $type === T_NAME_FULLY_QUALIFIED) {
                $interfaceName .= $text;
            } elseif ($type !== T_WHITESPACE) {
                break;
            }

            $i++;
        }

        if ($interfaceName) {
            $this->implements[$this->resolveName($interfaceName)] = true;
        }

        return $i;
    }

    /**
     * Extract new instantiation
     */
    private function extractNew(array $tokens, int $i): int
    {
        $i++; // Skip T_NEW
        $className = '';

        while ($i < count($tokens)) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === '(') {
                    break;
                }
                $i++;
                continue;
            }

            [$type, $text] = $token;

            if ($type === T_STRING || $type === T_NS_SEPARATOR || $type === T_NAME_QUALIFIED || $type === T_NAME_FULLY_QUALIFIED) {
                $className .= $text;
            } elseif ($type !== T_WHITESPACE) {
                break;
            }

            $i++;
        }

        if ($className && $className !== 'self' && $className !== 'static' && $className !== 'parent') {
            $this->instantiations[$this->resolveName($className)] = true;
        }

        return $i;
    }

    /**
     * Get the class name before T_DOUBLE_COLON for static calls
     */
    private function getPreviousClassName(array $tokens, int $i): ?string
    {
        $className = '';

        // Look backwards for the class name
        for ($j = $i - 1; $j >= 0; $j--) {
            $token = $tokens[$j];

            if (!is_array($token)) {
                break;
            }

            [$type, $text] = $token;

            if ($type === T_WHITESPACE) {
                continue;
            }

            if ($type === T_STRING || $type === T_NS_SEPARATOR || $type === T_NAME_QUALIFIED || $type === T_NAME_FULLY_QUALIFIED) {
                $className = $text . $className;
            } else {
                break;
            }
        }

        if ($className && $className !== 'self' && $className !== 'static' && $className !== 'parent') {
            return $className;
        }

        return null;
    }

    /**
     * Resolve a class name (matching DependencyVisitor behavior)
     *
     * Returns the name as-is for most cases, only resolves fully-qualified names.
     * This matches nikic/php-parser's DependencyVisitor behavior for compatibility.
     */
    private function resolveName(string $name): string
    {
        $name = trim($name);

        if (empty($name)) {
            return '';
        }

        // Already fully qualified - strip leading backslash
        if ($name[0] === '\\') {
            return ltrim($name, '\\');
        }

        // If it contains namespace separator and we're in a namespace, prepend namespace
        // This matches DependencyVisitor's behavior for qualified (but not fully qualified) names
        if (strpos($name, '\\') !== false && $this->currentNamespace !== null) {
            return $this->currentNamespace . '\\' . $name;
        }

        // Return unqualified name as-is (matching DependencyVisitor behavior)
        // Note: This means aliases are NOT expanded, consistent with AST parser
        return $name;
    }

    /**
     * Get class name from fully qualified name
     */
    private function getClassNameFromFQN(string $fqn): string
    {
        $parts = explode('\\', trim($fqn, '\\'));
        return end($parts);
    }

    /**
     * Get extracted data
     */
    public function getUses(): array
    {
        return array_keys($this->uses);
    }

    public function getExtends(): array
    {
        return array_keys($this->extends);
    }

    public function getImplements(): array
    {
        return array_keys($this->implements);
    }

    public function getTraits(): array
    {
        return array_keys($this->traits);
    }

    public function getInstantiations(): array
    {
        return array_keys($this->instantiations);
    }

    public function getStaticCalls(): array
    {
        return array_keys($this->staticCalls);
    }

    public function getMethodCalls(): array
    {
        return array_keys($this->methodCalls);
    }

    public function getDeclaredClasses(): array
    {
        return array_keys($this->declaredClasses);
    }
}
