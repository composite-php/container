<?php

declare(strict_types=1);

namespace Composite\Container;

use Closure;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Throwable;

use function array_key_exists;
use function array_keys;
use function class_exists;
use function implode;
use function interface_exists;
use function sprintf;

use const PHP_EOL;

class Container implements ContainerInterface
{
    /**
     * @var array<string, callable>
     */
    private array $userDefinitions = [];

    /**
     * This property acts as container for resolved entries,
     * so they don't have to be resolved again.
     *
     * @var array<string, mixed>
     */
    private array $resolved = [];

    /**
     * @var array<string, mixed>
     */
    private array $beingResolved = [];

    /**
     * @param iterable<string, callable> $definitions
     *
     * @throws ContainerException In case one of given definitions is invalid.
     */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $id => $definition) {
            if (!$definition instanceof Closure) {
                throw new ContainerException("Invalid definition for {$id}.");
            }

            $this->userDefinitions[$id] = $definition(...);
        }
    }

    /**
     * @template T
     *
     * @param string|class-string<T> $id Entry name or a class name.
     *
     * @return ($id is class-string ? T : mixed)
     * @throws NotFoundExceptionInterface
     * @throws ContainerExceptionInterface
     */
    public function get(string $id): mixed
    {
        // Firstly, check if entry has been resolved and return if so.
        if ($this->hasBeenResolved($id)) {
            return $this->resolved[$id];
        }

        // Secondly, resolve from user definition, if one is present.
        if ($this->isUserDefined($id)) {
            return $this->resolved[$id] = $this->userDefinitions[$id]($this);
        }

        // Assume that $id is a qualified name for class or interface.
        if (!class_exists($id) && !interface_exists($id)) {
            $msg = sprintf('No entry or class found for "%s".', $id);
            throw new NotFoundException($msg);
        }

        // Resolve and return instance of this class.
        return $this->resolved[$id] = $this->instantiate($id);
    }

    public function has(string $id): bool
    {
        return $this->isUserDefined($id) || class_exists($id) || interface_exists($id);
    }

    private function hasBeenResolved(string $id): bool
    {
        // Here 'isset' precedes 'array_key_exists' because its faster.
        // We still need 'array_key_exists' to return null entries.
        return isset($this->resolved[$id]) || array_key_exists($id, $this->resolved);
    }

    private function isUserDefined(string $id): bool
    {
        return isset($this->userDefinitions[$id]);
    }

    /**
     * @param class-string $qn
     *
     * @return object
     * @throws ContainerException
     */
    private function instantiate(string $qn): object
    {
        if (isset($this->beingResolved[$qn])) {
            $msg = sprintf('Cyclic dependency of %s.', $qn);
            throw new ContainerException($this->buildExceptionReport($msg));
        }

        $this->beingResolved[$qn] = true;

        $reflection = new ReflectionClass($qn);

        if (!$reflection->isInstantiable()) {
            $reason = sprintf('%s is not instantiable.', $qn);
            throw new ContainerException($this->buildExceptionReport($reason));
        }

        $constructor = $reflection->getConstructor();
        // No constructor defined or constructor requires no arguments? Just create new instance.
        if (!$constructor || !$constructor->getParameters()) {
            try {
                return new $qn();
            } catch (Throwable $exception) {
                $message = sprintf('Failed to instantiate %s.', $qn);
                throw new ContainerException($message, 0, $exception);
            }
        }

        try {
            $instance = new $qn(...$this->resolveParams($qn, $constructor));
            unset($this->beingResolved[$qn]);
            return $instance;
        } catch (Throwable $exception) {
            $message = sprintf('Failed to instantiate %s.', $qn);
            throw new ContainerException($message, 0, $exception);
        }
    }

    /**
     * @param string $qn
     * @param ReflectionMethod $constructor
     *
     * @return iterable<int, mixed>
     * @throws ContainerException
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    private function resolveParams(string $qn, ReflectionMethod $constructor): iterable
    {
        foreach ($constructor->getParameters() as $param) {
            if ($param->isOptional() && $param->isDefaultValueAvailable()) {
                yield $param->getDefaultValue();
                continue;
            }

            $paramType = $param->getType();

            if ($paramType === null) {
                $this->throwNonResolvableParameterTypeException(
                    $qn,
                    $param,
                    reason:'Null provided as parameter type',
                );
            }

            $this->checkForUnionType($qn, $param, $paramType);

            assert($paramType instanceof ReflectionNamedType);

            $this->checkForBuiltinType($qn, $param, $paramType);

            $typeHintedClassName = $paramType->getName();

            if (!class_exists($typeHintedClassName)) {
                $this->throwNonResolvableParameterTypeException(
                    $qn,
                    $param,
                    reason: "The type-hinted class for the type does not exist: $typeHintedClassName",
                );
            }

            $className = (new ReflectionClass($typeHintedClassName))->getName();

            yield $this->get($className);

            unset($this->beingResolved[$className]);
        }
    }

    private function checkForUnionType(
        string $qn,
        ReflectionParameter $param,
        ReflectionType $paramType
    ): void {
        if ($paramType instanceof ReflectionUnionType) {
            $typeNames = array_map(
                static fn(ReflectionNamedType $type): string => $type->getName(),
                $paramType->getTypes(),
            );

            $this->throwNonResolvableParameterTypeException(
                $qn,
                $param,
                reason: 'It has union type:' . implode('|', $typeNames),
            );
        }
    }

    private function checkForBuiltinType(
        string $qn,
        ReflectionParameter $param,
        ReflectionNamedType $paramType
    ): void {
        if ($paramType->isBuiltin()) {
            $this->throwNonResolvableParameterTypeException(
                $qn,
                $param,
                reason: 'A built-in type provided',
            );
        }
    }

    /**
     * Since dependency resolution is recursive, it's
     * important to provide developer with information about
     * where exactly in resolution stack the problem occurred.
     *
     * @param string $reason Exact reason for exception.
     *
     * @return string
     */
    private function buildExceptionReport(string $reason): string
    {
        $report = PHP_EOL;
        foreach (array_keys($this->beingResolved) as $className) {
            $report .= 'Resolving ' . $className . '…' . PHP_EOL;
        }

        return $report . $reason;
    }

    private function throwNonResolvableParameterTypeException(
        string $qn,
        ReflectionParameter $param,
        ?string $reason = null,
    ): never {
        $msg = sprintf(
            'Unable to resolve %s constructor parameter $%s of type %s (position %d).',
            $qn,
            $param->getName(),
            $param->getType() ? (string) $param->getType() : 'unknown',
            $param->getPosition() + 1,
        );

        if ($reason !== null) {
            $msg .= " Reason: $reason";
        }

        throw new ContainerException($this->buildExceptionReport($msg));
    }
}
