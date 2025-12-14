<?php

declare(strict_types=1);

namespace F4\PhpStan\Reflection\DBTransaction;

use F4\DB\QueryBuilderInterface;
use F4\DBTransaction;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ClassMemberReflection;
use PHPStan\Reflection\FunctionVariant;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\TrinaryLogic;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Generic\TemplateTypeMap;
use PHPStan\Type\IntegerType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\UnionType;

class DBTransactionMethodReflectionExtension implements MethodReflection
{

    public function __construct(private string $name, private ClassReflection $classReflection) {}

    public function getDeclaringClass(): ClassReflection
    {
        return $this->classReflection;
    }

    public function isStatic(): bool {
        // DBTransaction supports both static and instance calls for 'add' method
        // Both DBTransaction::add() and (new DBTransaction())->add() work
        return $this->name === 'add';
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
        return null;
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
        if ($this->name !== 'add') {
            return [];
        }

        // Create parameter for add() method
        // Signature: add(QueryBuilderInterface|array<QueryBuilderInterface> $query)
        $parameters = [
            new class implements ParameterReflection {
                public function getName(): string {
                    return 'query';
                }

                public function isOptional(): bool {
                    return false;
                }

                public function getType(): Type {
                    // QueryBuilderInterface|array<QueryBuilderInterface>
                    return new UnionType([
                        new ObjectType(QueryBuilderInterface::class),
                        new ArrayType(new IntegerType(), new ObjectType(QueryBuilderInterface::class))
                    ]);
                }

                public function passedByReference(): \PHPStan\Reflection\PassedByReference {
                    return \PHPStan\Reflection\PassedByReference::createNo();
                }

                public function isVariadic(): bool {
                    return false;
                }

                public function getDefaultValue(): ?Type {
                    return null;
                }
            }
        ];

        return [
            new FunctionVariant(
                TemplateTypeMap::createEmpty(),
                TemplateTypeMap::createEmpty(),
                $parameters,
                false,
                // add() returns $this for method chaining
                new ObjectType(DBTransaction::class),
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
        return TrinaryLogic::createYes();
    }

}
