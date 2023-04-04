<?php

declare(strict_types=1);

namespace Composite\Container\Tests\Fixtures;

class ConstructorRequiresBuiltinParamsWithDefaults
{
    public function __construct(
        public readonly int $integer = 1,
        public readonly bool $boolean = false,
        public readonly ?string $nullableString = null,
        public readonly float $float = 100.5
    ) {
    }
}
