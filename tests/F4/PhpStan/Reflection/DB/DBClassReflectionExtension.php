<?php

declare(strict_types = 1);

namespace F4\PhpStan\Reflection\DB;

use F4\DB;
use F4\DB\QueryBuilderInterface;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;
use PHPStan\Reflection\ReflectionProvider;

class DBClassReflectionExtension implements MethodsClassReflectionExtension
{

	public function __construct(private ReflectionProvider $reflectionProvider) {}

	public function hasMethod(ClassReflection $classReflection, string $methodName): bool
	{
		// Only apply this extension to F4\DB class
		if ($classReflection->getName() !== DB::class) {
			return false;
		}

		// DB class delegates all magic method calls to QueryBuilderInterface
		// Use PHPStan's reflection to check if the method exists on QueryBuilderInterface
		if (!$this->reflectionProvider->hasClass(QueryBuilderInterface::class)) {
			return false;
		}

		$queryBuilderReflection = $this->reflectionProvider->getClass(QueryBuilderInterface::class);
		return $queryBuilderReflection->hasMethod($methodName);
	}

	public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
	{
		return new DBMethodReflectionExtension($methodName, $classReflection, $this->reflectionProvider);
	}

}