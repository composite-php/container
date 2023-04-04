<?php

declare(strict_types=1);

namespace Composite\Container\Tests\Fixtures;

class AWithClassDependencies
{
    public function __construct(
        public readonly ClassWithEmptyConstructor $dep
    ) {
    }
}
