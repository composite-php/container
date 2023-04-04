<?php

declare(strict_types=1);

namespace Composite\Container;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
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
     * @var array<string>
     */
    private array $resolved = [];

    /**
     * @var array<string>
     */
    private array $beingResolved = [];

    /**
     * @param iterable<string, callable> $definitions
     * @throws ContainerException In case one of given definitions is invalid.
     */
    public function __construct(iterable $definitions = [])
    {
        foreach ($definitions as $id => $definition) {
            try {
                $this->userDefinitions[$id] = $definition(...);
            } catch (Throwable $exception) {
                $msg = sprintf('Invalid definition for %s.', $id);
                throw new ContainerException($msg, 0, $exception);
            }
        }
    }

    /**
     * @template T
     * @param string|class-string<T> $id Entry name or a class name.
     * @return mixed|T
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

        try {
            $reflection = new ReflectionClass($qn);
        } catch (Throwable $exception) {
            $reason = sprintf('Failed to reflect class %s because it does not exist.', $qn);
            throw new ContainerException($this->buildExceptionReport($reason), 0, $exception);
        }

        if (!$reflection->isInstantiable()) {
            $reason = sprintf('%s is not instantiable.', $qn);
            throw new ContainerException($this->buildExceptionReport($reason));
        }

        $constructor = $reflection->getConstructor();
        // No constructor defined or constructor requires no arguments? Just create new instance.
        if (!$constructor || !$constructor->getParameters()) {
            return new $qn();
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
     * @return iterable
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
            if ($paramType instanceof ReflectionUnionType) {
                $msgFormat = 'Unable to resolve %s constructor parameter $%s (position %d): it has union type %s.';
                $types = [];
                foreach ($paramType->getTypes() as $namedType) {
                    $types[] = $namedType->getName();
                }
                $message = sprintf(
                    $msgFormat,
                    $qn,
                    $param->getName(),
                    $param->getPosition() + 1,
                    implode('|', $types)
                );
                throw new ContainerException($this->buildExceptionReport($message));
            }

            try {
                /** @var ReflectionNamedType $paramType */
                $paramClass = !$paramType || $paramType->isBuiltin()
                    ? null
                    : new ReflectionClass($paramType->getName());
            } catch (ReflectionException $e) {
                $msg = sprintf(
                    'Unable to resolve %s constructor parameter $%s: %s.',
                    $qn,
                    $param->getName(),
                    $e->getMessage()
                );
                throw new ContainerException($this->buildExceptionReport($msg), 0, $e);
            }

            if (!$paramClass) {
                $msg = sprintf(
                    'Unable to resolve %s constructor parameter $%s of type %s (position %d).',
                    $qn,
                    $param->getName(),
                    $param->getType() ? (string) $param->getType() : 'unknown',
                    $param->getPosition() + 1
                );
                throw new ContainerException($this->buildExceptionReport($msg));
            }

            $className = $paramClass->getName();

            yield $this->get($className);

            unset($this->beingResolved[$className]);
        }
    }

    /**
     * Since dependency resolution is recursive, it's
     * important to provide developer with information about
     * where exactly in resolution stack the problem occurred.
     * @param string $reason Exact reason for exception.
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
}
