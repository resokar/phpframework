<?php
declare(strict_types=1);

namespace Resokar\Phpframework\System;

use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

class DIContainer
{
    /** @var array<class-string, object> */
    private array $instances = [];

    /** @var array<class-string, true> */
    private array $resolving = [];

    /**
     * Register or replace a shared instance. Existing consumers retain their references.
     *
     * @template T of object
     * @param class-string<T> $class
     * @param T $instance
     */
    public function set(string $class, object $instance): void
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (Throwable $exception) {
            throw $this->failure("Unknown class {$class}", $class, $exception);
        }

        $name = $reflection->getName();
        if (!$instance instanceof $name) {
            throw $this->failure("Instance must be of type {$name}", $name);
        }
        if (isset($this->resolving[$name])) {
            throw $this->failure("Cannot register {$name} while it is being resolved", $name);
        }

        $this->instances[$name] = $instance;
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class): object
    {
        try {
            $reflection = new ReflectionClass($class);
        } catch (Throwable $exception) {
            throw $this->failure("Unknown class {$class}", $class, $exception);
        }

        $name = $reflection->getName();
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }
        if (isset($this->resolving[$name])) {
            throw $this->failure("Circular dependency for {$name}", $name);
        }
        if (!$reflection->isInstantiable()) {
            throw $this->failure("Class {$name} is not instantiable", $name);
        }

        $this->resolving[$name] = true;
        try {
            $arguments = [];
            $constructor = $reflection->getConstructor();
            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()
                    || $type->allowsNull() || $parameter->isVariadic()) {
                    throw $this->failure(
                        "Invalid parameter {$name}::\$" . $parameter->getName()
                        . ': expected a non-nullable concrete class',
                    );
                }

                $dependency = $type->getName();
                $declaringClass = $constructor->getDeclaringClass();
                if ($dependency === 'self') {
                    $dependency = $declaringClass->getName();
                } elseif ($dependency === 'parent') {
                    $parent = $declaringClass->getParentClass();
                    if ($parent === false) {
                        throw $this->failure("No parent class for {$name}::\$" . $parameter->getName());
                    }
                    $dependency = $parent->getName();
                }

                try {
                    $arguments[] = $this->get($dependency);
                } catch (RuntimeException $exception) {
                    throw $this->failure(
                        "Cannot resolve {$name}::\$" . $parameter->getName() . ': ' . $exception->getMessage(),
                        previous: $exception,
                    );
                }
            }

            try {
                $instance = $reflection->newInstanceArgs($arguments);
            } catch (Throwable $exception) {
                throw $this->failure("Construction of {$name} failed: " . $exception->getMessage(), previous: $exception);
            }

            return $this->instances[$name] = $instance;
        } finally {
            unset($this->resolving[$name]);
        }
    }

    private function failure(string $message, ?string $next = null, ?Throwable $previous = null): RuntimeException
    {
        $chain = array_keys($this->resolving);
        if ($next !== null) {
            $chain[] = $next;
        }

        return new RuntimeException($message . ' [dependency chain: ' . implode(' -> ', $chain) . ']', 0, $previous);
    }
}
