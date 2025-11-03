<?php

declare(strict_types=1);

namespace Diffalyzer\Visitor;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

final class DependencyVisitor extends NodeVisitorAbstract
{
    private ?string $currentNamespace = null;
    private array $uses = [];
    private array $extends = [];
    private array $implements = [];
    private array $traits = [];
    private array $instantiations = [];
    private array $staticCalls = [];
    private array $methodCalls = [];
    private array $declaredClasses = [];
    private array $fileReferences = [];

    public function enterNode(Node $node): null|int|Node
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->currentNamespace = $node->name?->toString();
        }

        if ($node instanceof Node\Stmt\Use_) {
            foreach ($node->uses as $use) {
                $this->uses[] = $use->name->toString();
            }
        }

        if ($node instanceof Node\Stmt\GroupUse) {
            $prefix = $node->prefix->toString();
            foreach ($node->uses as $use) {
                $this->uses[] = $prefix . '\\' . $use->name->toString();
            }
        }

        if ($node instanceof Node\Stmt\Class_) {
            if ($node->name !== null) {
                $className = $this->currentNamespace !== null
                    ? $this->currentNamespace . '\\' . $node->name->toString()
                    : $node->name->toString();
                $this->declaredClasses[] = $className;
            }

            if ($node->extends !== null) {
                $this->extends[] = $this->resolveName($node->extends);
            }

            foreach ($node->implements as $interface) {
                $this->implements[] = $this->resolveName($interface);
            }
        }

        if ($node instanceof Node\Stmt\Interface_) {
            if ($node->name !== null) {
                $interfaceName = $this->currentNamespace !== null
                    ? $this->currentNamespace . '\\' . $node->name->toString()
                    : $node->name->toString();
                $this->declaredClasses[] = $interfaceName;
            }

            foreach ($node->extends as $interface) {
                $this->extends[] = $this->resolveName($interface);
            }
        }

        if ($node instanceof Node\Stmt\Trait_) {
            if ($node->name !== null) {
                $traitName = $this->currentNamespace !== null
                    ? $this->currentNamespace . '\\' . $node->name->toString()
                    : $node->name->toString();
                $this->declaredClasses[] = $traitName;
            }
        }

        if ($node instanceof Node\Stmt\TraitUse) {
            foreach ($node->traits as $trait) {
                $this->traits[] = $this->resolveName($trait);
            }
        }

        if ($node instanceof Node\Expr\New_) {
            if ($node->class instanceof Node\Name) {
                $this->instantiations[] = $this->resolveName($node->class);
            }
        }

        if ($node instanceof Node\Expr\StaticCall) {
            if ($node->class instanceof Node\Name) {
                $this->staticCalls[] = $this->resolveName($node->class);
            }
        }

        if ($node instanceof Node\Expr\MethodCall) {
            if ($node->var instanceof Node\Expr\Variable && is_string($node->var->name)) {
                $this->methodCalls[] = $node->var->name;
            }
        }

        if ($node instanceof Node\Expr\FuncCall) {
            $this->extractFileReferences($node);
        }

        if ($node instanceof Node\Expr\Include_) {
            $this->extractFilePathFromNode($node->expr);
        }

        return null;
    }

    private function extractFileReferences(Node\Expr\FuncCall $node): void
    {
        if (!$node->name instanceof Node\Name) {
            return;
        }

        $funcName = $node->name->toString();
        $fileLoadingFunctions = [
            'file_get_contents', 'file', 'readfile', 'fopen',
            'include', 'include_once', 'require', 'require_once',
            'parse_ini_file', 'simplexml_load_file', 'json_decode',
        ];

        if (in_array($funcName, $fileLoadingFunctions, true) && !empty($node->args)) {
            $this->extractFilePathFromNode($node->args[0]->value);
        }
    }

    private function extractFilePathFromNode(Node $node): void
    {
        if ($node instanceof Node\Scalar\String_) {
            $path = $node->value;
            if ($this->looksLikeFilePath($path)) {
                $this->fileReferences[] = $path;
            }
        }

        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            $this->extractConcatenatedPath($node);
        }
    }

    private function extractConcatenatedPath(Node\Expr\BinaryOp\Concat $node): void
    {
        $parts = [];
        $this->collectConcatParts($node, $parts);

        $hasDir = false;
        $pathParts = [];

        foreach ($parts as $part) {
            if ($part === '__DIR__' || $part === 'dirname') {
                $hasDir = true;
            } elseif (is_string($part)) {
                $pathParts[] = $part;
            }
        }

        if ($hasDir && !empty($pathParts)) {
            $path = implode('', $pathParts);
            if ($this->looksLikeFilePath($path)) {
                $this->fileReferences[] = $path;
            }
        }
    }

    private function collectConcatParts(Node $node, array &$parts): void
    {
        if ($node instanceof Node\Expr\BinaryOp\Concat) {
            $this->collectConcatParts($node->left, $parts);
            $this->collectConcatParts($node->right, $parts);
        } elseif ($node instanceof Node\Scalar\String_) {
            $parts[] = $node->value;
        } elseif ($node instanceof Node\Scalar\MagicConst\Dir) {
            $parts[] = '__DIR__';
        } elseif ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $parts[] = $node->name->toString();
        }
    }

    private function looksLikeFilePath(string $path): bool
    {
        return preg_match('/\.(php|json|xml|ya?ml|csv|txt|sql|ini)$/i', $path) === 1 ||
               str_contains($path, '/fixtures/') ||
               str_contains($path, '/data/') ||
               str_contains($path, '/resources/');
    }

    public function getUses(): array
    {
        return array_unique($this->uses);
    }

    public function getExtends(): array
    {
        return array_unique($this->extends);
    }

    public function getImplements(): array
    {
        return array_unique($this->implements);
    }

    public function getTraits(): array
    {
        return array_unique($this->traits);
    }

    public function getInstantiations(): array
    {
        return array_unique($this->instantiations);
    }

    public function getStaticCalls(): array
    {
        return array_unique($this->staticCalls);
    }

    public function getMethodCalls(): array
    {
        return array_unique($this->methodCalls);
    }

    public function getDeclaredClasses(): array
    {
        return array_unique($this->declaredClasses);
    }

    public function getFileReferences(): array
    {
        return array_unique($this->fileReferences);
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
