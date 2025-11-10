<?php

declare(strict_types=1);

namespace Diffalyzer\Parser;

/**
 * Extracts method-level call information from PHP code
 * Builds a map of which methods call which other methods
 */
final class MethodCallExtractor
{
    private ?string $currentNamespace = null;
    private array $useAliases = []; // alias => fullyQualifiedName
    private ?string $currentClass = null;
    private ?string $currentMethod = null;
    private array $methodCalls = []; // ['ClassName::methodName' => ['CalledClass::calledMethod', ...]]

    /**
     * Extract method-level call graph from PHP code
     *
     * @return array<string, array<string>> Caller method => array of called methods
     */
    public function extract(string $code): array
    {
        $this->reset();

        $tokens = @token_get_all($code);
        if ($tokens === false) {
            return [];
        }

        $this->processTokens($tokens);

        return $this->methodCalls;
    }

    private function reset(): void
    {
        $this->currentNamespace = null;
        $this->useAliases = [];
        $this->currentClass = null;
        $this->currentMethod = null;
        $this->methodCalls = [];
    }

    private function processTokens(array $tokens): void
    {
        $count = count($tokens);
        $braceDepth = 0;
        $methodBraceDepth = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // Track brace depth to know when we're inside a method
            if (!is_array($token)) {
                if ($token === '{') {
                    $braceDepth++;
                    if ($this->currentMethod !== null && $methodBraceDepth === null) {
                        $methodBraceDepth = $braceDepth;
                    }
                } elseif ($token === '}') {
                    if ($braceDepth === $methodBraceDepth) {
                        // Exiting method
                        $this->currentMethod = null;
                        $methodBraceDepth = null;
                    }
                    $braceDepth--;
                }
                continue;
            }

            [$type, $text] = $token;

            switch ($type) {
                case T_NAMESPACE:
                    $i = $this->extractNamespace($tokens, $i);
                    break;

                case T_USE:
                    // Only process import uses, not trait uses
                    if (!$this->isInsideClass($braceDepth)) {
                        $i = $this->extractUseStatement($tokens, $i);
                    }
                    break;

                case T_CLASS:
                case T_INTERFACE:
                case T_TRAIT:
                    $this->currentClass = $this->extractNextIdentifier($tokens, $i);
                    break;

                case T_FUNCTION:
                    $methodName = $this->extractNextIdentifier($tokens, $i);
                    if ($methodName !== null && $this->currentClass !== null) {
                        $fqn = $this->currentNamespace !== null
                            ? "{$this->currentNamespace}\\{$this->currentClass}::{$methodName}"
                            : "{$this->currentClass}::{$methodName}";
                        $this->currentMethod = $fqn;
                        $methodBraceDepth = null;
                    }
                    break;

                case T_DOUBLE_COLON:
                    // Static method call: Class::method()
                    if ($this->currentMethod !== null) {
                        $className = $this->getPreviousClassName($tokens, $i);
                        $methodName = $this->getNextMethodName($tokens, $i);
                        if ($className !== null && $methodName !== null) {
                            $resolvedClass = $this->resolveName($className);
                            $calledMethod = "{$resolvedClass}::{$methodName}";
                            $this->addMethodCall($this->currentMethod, $calledMethod);
                        }
                    }
                    break;

                case T_OBJECT_OPERATOR:
                    // Instance method call: $obj->method()
                    if ($this->currentMethod !== null) {
                        $varName = $this->getPreviousVariableName($tokens, $i);
                        $methodName = $this->getNextMethodName($tokens, $i);

                        if ($methodName !== null) {
                            // If it's $this, we know the class
                            if ($varName === '$this' && $this->currentClass !== null) {
                                $fqn = $this->currentNamespace !== null
                                    ? "{$this->currentNamespace}\\{$this->currentClass}::{$methodName}"
                                    : "{$this->currentClass}::{$methodName}";
                                $this->addMethodCall($this->currentMethod, $fqn);
                            } else {
                                // For other variables, we record the call but can't resolve the class
                                // Format: $var->methodName (to be resolved later)
                                $this->addMethodCall($this->currentMethod, "{$varName}->{$methodName}");
                            }
                        }
                    }
                    break;
            }
        }
    }

    private function addMethodCall(string $caller, string $callee): void
    {
        if (!isset($this->methodCalls[$caller])) {
            $this->methodCalls[$caller] = [];
        }
        $this->methodCalls[$caller][] = $callee;
    }

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

    private function extractUseStatement(array $tokens, int $i): int
    {
        $i++; // Skip T_USE
        $fullName = '';
        $alias = null;

        while ($i < count($tokens)) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                if ($token === ';') {
                    if ($fullName) {
                        $finalAlias = $alias ?? $this->getClassNameFromFQN($fullName);
                        $this->useAliases[$finalAlias] = ltrim($fullName, '\\');
                    }
                    break;
                }
                $i++;
                continue;
            }

            [$type, $text] = $token;

            if ($type === T_STRING || $type === T_NS_SEPARATOR || $type === T_NAME_QUALIFIED) {
                $fullName .= $text;
            } elseif ($type === T_AS) {
                $i++;
                $alias = $this->extractNextIdentifier($tokens, $i);
            }

            $i++;
        }

        return $i;
    }

    private function extractNextIdentifier(array $tokens, int $position): ?string
    {
        $count = count($tokens);
        for ($i = $position + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_STRING) {
                return $token[1];
            }

            // Stop at certain tokens
            if (in_array($token[0], [T_EXTENDS, T_IMPLEMENTS, T_USE, T_FUNCTION, T_CLASS], true)) {
                break;
            }
        }

        return null;
    }

    private function getPreviousClassName(array $tokens, int $position): ?string
    {
        for ($i = $position - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_STRING || $token[0] === T_NAME_QUALIFIED) {
                return $token[1];
            }

            if ($token[0] === T_WHITESPACE) {
                continue;
            }

            // Stop if we hit something else
            break;
        }

        return null;
    }

    private function getPreviousVariableName(array $tokens, int $position): ?string
    {
        for ($i = $position - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_VARIABLE) {
                return $token[1];
            }

            if ($token[0] === T_WHITESPACE) {
                continue;
            }

            break;
        }

        return null;
    }

    private function getNextMethodName(array $tokens, int $position): ?string
    {
        $count = count($tokens);
        for ($i = $position + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_STRING) {
                return $token[1];
            }

            if ($token[0] === T_WHITESPACE) {
                continue;
            }

            // Stop if we hit something that's not a method name
            break;
        }

        return null;
    }

    private function resolveName(string $name): string
    {
        // If it's already fully qualified, return as-is
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        // Check if it's an alias
        if (isset($this->useAliases[$name])) {
            return $this->useAliases[$name];
        }

        // Check if it's a multi-part name with an alias prefix
        $parts = explode('\\', $name);
        if (count($parts) > 1 && isset($this->useAliases[$parts[0]])) {
            $parts[0] = $this->useAliases[$parts[0]];
            return implode('\\', $parts);
        }

        // If we have a namespace, prepend it
        if ($this->currentNamespace !== null) {
            return $this->currentNamespace . '\\' . $name;
        }

        return $name;
    }

    private function getClassNameFromFQN(string $fqn): string
    {
        $parts = explode('\\', $fqn);
        return end($parts);
    }

    private function isInsideClass(int $braceDepth): bool
    {
        return $braceDepth > 0;
    }
}
