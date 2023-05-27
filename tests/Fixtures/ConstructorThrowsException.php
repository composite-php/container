<?php

declare(strict_types=1);

namespace Composite\Container\Tests\Fixtures;

use RuntimeException;

class ConstructorThrowsException
{
    public function __construct()
    {
        throw new RuntimeException('Terrible error!');
    }
}
