<?php

declare(strict_types=1);

namespace Composite\Container\Tests\Fixtures;

class BWithEnumDependencyWithDefault
{
    public function __construct(
        public readonly UserDefinedEnum $dep = UserDefinedEnum::ONE
    ) {
    }
}
