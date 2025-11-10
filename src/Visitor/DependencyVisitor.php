<?php

declare(strict_types=1);

namespace Diffalyzer\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class DependencyVisitor extends NodeVisitorAbstract
{
    private ?string $currentNamespace = null;
    // Use array keys for O(1) deduplication instead of array_unique
    private array $uses = [];
    private array $extends = [];
    private array $implements = [];
    private array $traits = [];
    private array $instantiations = [];
    private array $staticCalls = [];
    private array $methodCalls = [];
    private array $declaredClasses = [];
    private array $includes = [];

    public function enterNode(Node $node): null|int|Node
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString();
        }

        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $this->uses[$use->name->toString()] = true;
            }
        }

        if ($node instanceof Node\Stmt\GroupUse) {
            $prefix = $node->prefix->toString();
            foreach ($node->uses as $use) {
                $this->uses[$prefix . '\\' . $use->name->toString()] = true;
            }
        }

        if ($node instanceof Node\Stmt\Class_) {
            if ($node->name !== null) {
                $className = $this->currentNamespace !== null
                    ? $this->currentNamespace . '\\' . $node->name->toString()
                    : $node->name->toString();
                $this->declaredClasses[$className] = true;
            }

            if ($node->extends !== null) {
                $this->extends[$this->resolveName($node->extends)] = true;
            }

            foreach ($node->implements as $interface) {
                $this->implements[$this->resolveName($interface)] = true;
            }
        }

        if ($node instanceof Node\Stmt\Interface_) {
            if ($node->name !== null) {
                $interfaceName = $this->currentNamespace !== null
                    ? $this->currentNamespace . '\\' . $node->name->toString()
                    : $node->name->toString();
                $this->declaredClasses[$interfaceName] = true;
            }

            foreach ($node->extends as $interface) {
                $this->extends[$this->resolveName($interface)] = true;
            }
        }

        if ($node instanceof Node\Stmt\Trait_) {
            if ($node->name !== null) {
                $traitName = $this->currentNamespace !== null
                    ? $this->currentNamespace . '\\' . $node->name->toString()
                    : $node->name->toString();
                $this->declaredClasses[$traitName] = true;
            }
        }

        if ($node instanceof Node\Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $this->traits[$this->resolveName($trait)] = true;
            }
        }

        if ($node instanceof Node\Expr\New_) {
            if ($node->class instanceof Node\Name) {
                $this->instantiations[$this->resolveName($node->class)] = true;
            }
        }

        if ($node instanceof Node\Expr\StaticCall) {
            if ($node->class instanceof Node\Name) {
                $this->staticCalls[$this->resolveName($node->class)] = true;
            }
        }

        if ($node instanceof Node\Expr\MethodCall) {
            if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $this->methodCalls[$node->var->name] = true;
            }
        }

        if ($node instanceof Node\Expr\Include_) {
            // Only track static string includes (not dynamic paths with variables)
            if ($node->expr instanceof Node\Scalar\String_) {
                $this->includes[$node->expr->value] = true;
            }
        }

        return null;
    }

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

    public function getIncludes(): array
    {
        return array_keys($this->includes);
    }

    private function resolveName(Node\Name $name): string
    {
        if ($name->isFullyQualified()) {
            return $name->toString();
        }

        if ($this->currentNamespace !== null && !$name->isUnqualified()) {
            return $this->currentNamespace . '\\' . $name->toString();
        }

        return $name->toString();
    }
}
