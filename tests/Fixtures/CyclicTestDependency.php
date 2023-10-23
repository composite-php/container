<?php

declare(strict_types=1);

namespace Composite\Container\Tests\Fixtures;

class CyclicTestDependency
{
    public function __construct(ConstructorThrowsException $dep)
    {
    }
}
