<?php

declare(strict_types=1);

namespace Composite\Container\Tests\Fixtures;

class CyclicDependency
{
    public function __construct(CyclicDependency $dep)
    {
    }
}
