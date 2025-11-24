<?php

declare(strict_types=1);

namespace F4\DB;

use F4\DB\FragmentInterface;
use F4\DB\PreparedStatement;

interface FragmentCollectionInterface
{
    public function addExpression(mixed $expression): void;
    public function append(FragmentInterface|string $fragment): static;
    public function findFragmentCollectionByName(string $name): ?FragmentCollectionInterface;
    public function getFragments(): array;
    public function getName(): ?string;
    public function getPreparedStatement(?callable $enumeratorFunction = null): PreparedStatement;
    public function resetName(): void;
    public function withName(string $name): static;
    public function withPrefix(string $prefix): static;
}