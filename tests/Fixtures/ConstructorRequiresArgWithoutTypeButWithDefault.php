<?php

declare(strict_types=1);

namespace Composite\Container\Tests\Fixtures;

class ConstructorRequiresArgWithoutTypeButWithDefault
{
    /**
     * No type specified, however, default value is available.
     * @param $arg
     */
    public function __construct(
        public $arg = 1
    ) {
    }
}
