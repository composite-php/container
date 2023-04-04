<?php

declare(strict_types=1);

namespace Composite\Container\Tests\Fixtures;

class ConstructorRequiresBuiltinParamWithoutDefault
{
    public function __construct(
        public readonly int $integer
    ) {
    }
}
