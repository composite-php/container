<?php

declare(strict_types=1);

namespace Composite\Container\Tests\Fixtures;

class ConstructorRequiresSameDependencyTwice
{
    public function __construct(AWithClassDependencies $a, AWithClassDependencies $b)
    {
    }
}
