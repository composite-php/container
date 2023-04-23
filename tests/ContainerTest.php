<?php

declare(strict_types=1);

namespace Composite\Container\Tests;

use Composite\Container\Container;
use Composite\Container\ContainerException;
use Composite\Container\Tests\Fixtures\AWithClassDependencies;
use Composite\Container\Tests\Fixtures\BWithEnumDependencyWithDefault;
use Composite\Container\Tests\Fixtures\ConstructorRequiresArgWithoutTypeButWithDefault;
use Composite\Container\Tests\Fixtures\ConstructorRequiresBuiltinParamsWithDefaults;
use Composite\Container\Tests\Fixtures\ClassWithEmptyConstructor;
use Composite\Container\Tests\Fixtures\ClassWithoutConstructor;
use Composite\Container\Tests\Fixtures\ConstructorRequiresBuiltinParamWithoutDefault;
use Composite\Container\Tests\Fixtures\ConstructorRequiresSameDependencyTwice;
use Composite\Container\Tests\Fixtures\CWithUnionType;
use Composite\Container\Tests\Fixtures\CyclicDependency;
use Composite\Container\Tests\Fixtures\DWithEnumDependencyWithoutDefault;
use Composite\Container\Tests\Fixtures\UserDefinedEnum;
use Composite\Container\Tests\Fixtures\UserDefinedInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function class_exists;

#[CoversClass(Container::class)]
class ContainerTest extends TestCase
{
    private Container $container;

    protected function setUp(): void
    {
        $this->container = new Container();
    }

    public function testConstructorThrowsOnInvalidDefinition(): void
    {
        $definitions = [
            'foo' => static fn() => 'foo',
            'bar' => null,
        ];

        $this->expectExceptionMessage('Invalid definition for bar.');

        /* @phpstan-ignore-next-line */
        new Container($definitions);
    }

    public function testHasReturnsTrueWhenDefinitionIsPresent(): void
    {
        $definitions = [
            'foo' => static function () {
            },
        ];

        $container = new Container($definitions);

        self::assertTrue($container->has('foo'));
    }

    public function testHasReturnsTrueIfClassExists(): void
    {
        self::assertTrue($this->container->has(ClassWithoutConstructor::class));
    }

    public function testHasReturnsTrueIfInterfaceExists(): void
    {
        self::assertTrue($this->container->has(UserDefinedInterface::class));
    }

    public function testHasReturnsFalseWhenClassDoesNotExist(): void
    {
        self::assertFalse(class_exists('NonExistentClass'));
        self::assertFalse($this->container->has('NonExistentClass'));
    }

    public function testGetPrioritizesDefinitionsOverAutowiring(): void
    {
        $definitions = [
            ClassWithoutConstructor::class => function () {
                return new ClassWithEmptyConstructor();
            },
        ];

        $container = new Container($definitions);

        self::assertInstanceOf(
            ClassWithEmptyConstructor::class,
            $container->get(ClassWithoutConstructor::class),
        );
    }

    public function testGetResolvesWithClassWithoutConstructor(): void
    {
        $returned = $this->container->get(ClassWithoutConstructor::class);

        self::assertInstanceOf(ClassWithoutConstructor::class, $returned);
    }

    public function testGetResolvesWithClassWithEmptyConstructor(): void
    {
        $returned = $this->container->get(ClassWithEmptyConstructor::class);

        self::assertInstanceOf(ClassWithEmptyConstructor::class, $returned);
    }

    public function testGetThrowsWhenRequestedForNonInstantiable(): void
    {
        $this->expectExceptionMessage(
            sprintf('[%s] is not instantiable.', UserDefinedInterface::class),
        );

        $this->container->get(UserDefinedInterface::class);
    }

    public function testGetReturnsSameInstanceOnceResolved(): void
    {
        self::assertSame(
            $this->container->get(ClassWithoutConstructor::class),
            $this->container->get(ClassWithoutConstructor::class),
        );
    }

    public function testGetInjectsDefaultBuiltinParamsCorrectly(): void
    {
        $returned = $this->container->get(ConstructorRequiresBuiltinParamsWithDefaults::class);

        self::assertSame(1, $returned->integer);
        self::assertFalse($returned->boolean);
        self::assertNull($returned->nullableString);
        self::assertSame(100.5, $returned->float);
    }

    public function testGetThrowsWhenBuiltinArgsHaveNoDefaults(): void
    {
        $className = ConstructorRequiresBuiltinParamWithoutDefault::class;

        $this->expectExceptionObject(
            new ContainerException(
                "Unable to resolve $className constructor parameter \$integer of type int (position 1).",
            ),
        );

        $this->container->get($className);
    }

    public function testGetInjectsDefaultValueEvenIfArgTypeIsNotSpecified(): void
    {
        $resolved = $this->container->get(ConstructorRequiresArgWithoutTypeButWithDefault::class);

        self::assertSame(1, $resolved->arg);
    }

    public function testGetInstantiatesClassDependency(): void
    {
        $resolved = $this->container->get(AWithClassDependencies::class);

        self::assertInstanceOf(AWithClassDependencies::class, $resolved);
        self::assertInstanceOf(ClassWithEmptyConstructor::class, $resolved->dep);
    }

    public function testGetInjectsEnumsWhenDefaultIsGiven(): void
    {
        $resolved = $this->container->get(BWithEnumDependencyWithDefault::class);

        self::assertSame(UserDefinedEnum::ONE, $resolved->dep);
    }

    public function testGetThrowsWhenConstructorRequiresEnumWithoutDefault(): void
    {
        $class = DWithEnumDependencyWithoutDefault::class;

        $this->expectExceptionObject(
            new ContainerException('[Composite\Container\Tests\Fixtures\UserDefinedEnum] is not instantiable.'),
        );

        $this->container->get($class);
    }

    public function testGetThrowsOnUnionType(): void
    {
        $className = CWithUnionType::class;

        $unionTypes = implode('|', [
            ClassWithEmptyConstructor::class,
            ClassWithoutConstructor::class,
        ]);

        $message = sprintf(
            'Unable to resolve %s constructor parameter $%s (position %d). It has union type: [%s]',
            $className,
            'dep',
            1,
            $unionTypes
        );

        $this->expectExceptionObject(new ContainerException($message));

        $this->container->get($className);
    }

    public function testDetectsCyclicDependency(): void
    {
        $this->expectExceptionObject(
            new ContainerException('Cyclic dependency of [Composite\Container\Tests\Fixtures\CyclicDependency]'),
        );

        $this->container->get(CyclicDependency::class);
    }

    public function testHandlesInjectionOfSameTwiceInSameConstructor(): void
    {
        $result = $this->container->get(ConstructorRequiresSameDependencyTwice::class);

        self::assertInstanceOf(ConstructorRequiresSameDependencyTwice::class, $result);
    }
}
