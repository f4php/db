<?php

declare(strict_types = 1);

namespace F4\PhpStan\Reflection\DBTransaction;

use F4\DBTransaction;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\MethodsClassReflectionExtension;

class DBTransactionClassReflectionExtension implements MethodsClassReflectionExtension
{

	public function hasMethod(ClassReflection $classReflection, string $methodName): bool
	{
		// Only apply this extension to F4\DBTransaction class
		if ($classReflection->getName() !== DBTransaction::class) {
			return false;
		}

		// DBTransaction only supports 'add' method via magic __call and __callStatic
		return $methodName === 'add';
	}

	public function getMethod(ClassReflection $classReflection, string $methodName): MethodReflection
	{
		return new DBTransactionMethodReflectionExtension($methodName, $classReflection);
	}

}
