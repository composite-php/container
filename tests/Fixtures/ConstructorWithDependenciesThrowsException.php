<?php

declare(strict_types=1);

namespace Composite\Container\Tests\Fixtures;

use RuntimeException;

class ConstructorWithDependenciesThrowsException extends AWithClassDependencies
{
    public function __construct(ClassWithEmptyConstructor $dep)
    {
        parent::__construct($dep);
        throw new RuntimeException('Terrible exception!');
    }
}
