<?php

declare(strict_types=1);

namespace Composite\Container\Tests\Fixtures;

class CWithUnionType
{
    public function __construct(
        public readonly ClassWithEmptyConstructor|ClassWithoutConstructor $dep
    ) {
    }
}
