<?php

declare(strict_types=1);

namespace F4\PhpStan\Reflection\DB;

use F4\DB\QueryBuilderInterface;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\TrinaryLogic;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

class DBMethodReflectionExtension implements MethodReflection
{
    private ?MethodReflection $queryBuilderMethod = null;

    public function __construct(
        private string $name,
        private ClassReflection $classReflection,
        private ReflectionProvider $reflectionProvider
    )
    {
        // Cache the reflection of the QueryBuilderInterface method using PHPStan's reflection
        if ($this->reflectionProvider->hasClass(QueryBuilderInterface::class)) {
            $queryBuilderReflection = $this->reflectionProvider->getClass(QueryBuilderInterface::class);
            if ($queryBuilderReflection->hasMethod($this->name)) {
                $this->queryBuilderMethod = $queryBuilderReflection->getNativeMethod($this->name);
            }
        }
    }

    public function getDeclaringClass(): ClassReflection
    {
        return $this->classReflection;
    }

    public function isStatic(): bool {
        // DB supports both static and instance calls for all QueryBuilder methods
        // Both DB::select() and (new DB())->select() work
        return true;
    }

    public function isPrivate(): bool
    {
        return false;
    }

    public function isPublic(): bool
    {
        return true;
    }

    public function getDocComment(): ?string {
        return $this->queryBuilderMethod?->getDocComment() ?: null;
    }

    public function getName(): string {
        return $this->name;
    }

    public function getPrototype(): ClassMemberReflection {
        return $this;
    }

    /**
     * @return \PHPStan\Reflection\ParametersAcceptor[]
     */
    public function getVariants(): array {
        // If we have the QueryBuilderInterface method, delegate to its variants
        // but override the return type to be QueryBuilderInterface
        if ($this->queryBuilderMethod !== null) {
            $variants = $this->queryBuilderMethod->getVariants();
            $result = [];

            foreach ($variants as $variant) {
                $result[] = new FunctionVariant(
                    $variant->getTemplateTypeMap(),
                    $variant->getResolvedTemplateTypeMap(),
                    $variant->getParameters(),
                    $variant->isVariadic(),
                    // DB's __call and __callStatic return QueryBuilderInterface, not DB
                    new ObjectType(QueryBuilderInterface::class),
                );
            }

            return $result;
        }

        // Fallback for unknown methods
        return [
            new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                TemplateTypeMap::createEmpty(),
                [],
                true,
                new ObjectType(QueryBuilderInterface::class),
            )
        ];
    }

    public function isDeprecated(): TrinaryLogic {
        return TrinaryLogic::createNo();
    }

    public function getDeprecatedDescription(): ?string {
        return null;
    }

    public function isFinal(): TrinaryLogic {
        return TrinaryLogic::createNo();
    }

    public function isInternal(): TrinaryLogic {
        return TrinaryLogic::createNo();
    }

    public function getThrowType(): ?Type {
        return null;
    }

    public function hasSideEffects(): TrinaryLogic {
        return TrinaryLogic::createMaybe();
    }

}