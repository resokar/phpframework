<?php
declare(strict_types=1);

namespace Resokar\Phpframework\Test\System;

use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Resokar\Phpframework\System\DIContainer;
use RuntimeException;

final class DIContainerTest extends TestCase
{
    public function testRegisteredPdoIsSharedAndInjected(): void
    {
        $container = new DIContainer();
        $pdo = $this->createStub(PDO::class);
        $container->set(strtolower(PDO::class), $pdo);

        self::assertSame($pdo, $container->get(PDO::class));
        self::assertSame($pdo, $container->get(strtolower(PDO::class)));
        self::assertSame($pdo, $container->get(PdoConsumer::class)->pdo);
    }

    public function testRegistersInterfaceImplementationAndSubclass(): void
    {
        $container = new DIContainer();
        $implementation = new class implements Contract {};
        $container->set(Contract::class, $implementation);
        self::assertSame($implementation, $container->get(Contract::class));
        self::assertSame($implementation, $container->get(ContractConsumer::class)->value);

        $leaf = new class extends Leaf {};
        $container->set(Leaf::class, $leaf);
        self::assertSame($leaf, $container->get(Branch::class)->leaf);
    }

    public function testReplacementPreservesExistingConsumerReferences(): void
    {
        $container = new DIContainer();
        $branch = $container->get(Branch::class);
        $original = $branch->leaf;
        $replacement = new Leaf();
        $container->set(Leaf::class, $replacement);

        self::assertSame($replacement, $container->get(Leaf::class));
        self::assertSame($original, $container->get(Branch::class)->leaf);

        $next = new Leaf();
        $container->set(strtolower(Leaf::class), $next);
        self::assertSame($next, $container->get(Leaf::class));
    }

    public function testRejectsIncompatibleRegistrationWithoutReplacingInstance(): void
    {
        $container = new DIContainer();
        $original = $container->get(Leaf::class);
        try {
            $container->set(Leaf::class, new \stdClass());
            self::fail('Expected registration to fail');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Instance must be of type ' . Leaf::class, $exception->getMessage());
            self::assertStringContainsString('dependency chain:', $exception->getMessage());
        }
        self::assertSame($original, $container->get(Leaf::class));
    }

    public function testRejectsUnknownRegistrationType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown class ' . __NAMESPACE__ . '\\MissingClass');
        (new DIContainer())->set(__NAMESPACE__ . '\\MissingClass', new Leaf());
    }

    public function testRejectsRegistrationDuringResolutionAndAllowsRetry(): void
    {
        $container = new DIContainer();
        $container->set(DIContainer::class, $container);
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $container->get(RegisteringService::class);
                self::fail('Expected registration to fail');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString(
                    'Cannot register ' . RegisteringService::class . ' while it is being resolved',
                    $exception->getMessage(),
                );
                self::assertStringContainsString('dependency chain:', $exception->getMessage());
            }
        }
    }

    public function testRecursivelyResolvesAndSharesInstances(): void
    {
        $container = new DIContainer();
        $root = $container->get(RootService::class);

        self::assertSame($root, $container->get(RootService::class));
        self::assertSame($root->branch->leaf, $root->leaf);
        self::assertSame($root->leaf, $container->get(Leaf::class));
        self::assertSame($root->leaf, $container->get(strtolower(Leaf::class)));
        self::assertNotSame($root->leaf, (new DIContainer())->get(Leaf::class));
    }

    #[DataProvider('invalidClasses')]
    public function testRejectsInvalidClassesAndParameters(string $class, string $detail): void
    {
        $container = new DIContainer();
        // A failed attempt must not leave a false circular-dependency marker.
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $container->get($class);
                self::fail('Expected resolution to fail');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString($detail, $exception->getMessage());
                self::assertStringContainsString($class, $exception->getMessage());
                self::assertStringContainsString('dependency chain:', $exception->getMessage());
            }
        }
    }

    public static function invalidClasses(): iterable
    {
        foreach ([NullableParameter::class, ScalarParameter::class, UntypedParameter::class,
            VariadicParameter::class, UnionParameter::class, IntersectionParameter::class,
            ObjectParameter::class, MixedParameter::class] as $class) {
            yield $class => [$class, '$value'];
        }
        yield [InterfaceParameter::class, '$value'];
        yield [AbstractParameter::class, '$value'];
        yield [Contract::class, 'not instantiable'];
        yield [AbstractService::class, 'not instantiable'];
        yield [PrivateConstructor::class, 'not instantiable'];
        yield [ProtectedConstructor::class, 'not instantiable'];
        yield [__NAMESPACE__ . '\\MissingClass', 'Unknown class'];
        yield [CycleA::class, 'Circular dependency'];
        yield [SelfParameter::class, 'Circular dependency'];
    }

    public function testResolvesParentInInheritedConstructorDeclarationContext(): void
    {
        $container = new DIContainer();
        $service = $container->get(InheritedParentParameter::class);
        self::assertSame($container->get(Leaf::class), $service->value);
    }

    public function testInheritedSelfTypeUsesDeclaringClass(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Circular dependency for ' . SelfParameter::class);
        (new DIContainer())->get(InheritedSelfParameter::class);
    }

    public function testConstructorFailurePreservesCauseAndDependenciesAndAllowsRetry(): void
    {
        FailingService::$fail = true;
        FailingService::$seen = null;
        $container = new DIContainer();
        try {
            $container->get(FailingService::class);
            self::fail('Expected construction to fail');
        } catch (RuntimeException $exception) {
            self::assertInstanceOf(LogicException::class, $exception->getPrevious());
            self::assertStringContainsString('deliberate failure', $exception->getMessage());
        }
        $dependency = FailingService::$seen;
        self::assertInstanceOf(Leaf::class, $dependency);
        self::assertSame($dependency, $container->get(Leaf::class));

        FailingService::$fail = false;
        $service = $container->get(FailingService::class);
        self::assertSame($dependency, $service->leaf);
        self::assertSame($service, $container->get(FailingService::class));
    }
}

class Leaf {}
class PdoConsumer
{
    public function __construct(public PDO $pdo) {}
}
class ContractConsumer
{
    public function __construct(public Contract $value) {}
}
class RegisteringService
{
    public function __construct(DIContainer $container)
    {
        $container->set(self::class, $this);
    }
}
class Branch
{
    public function __construct(public Leaf $leaf) {}
}
class RootService
{
    public function __construct(public Branch $branch, public Leaf $leaf) {}
}
class NullableParameter
{
    public function __construct(?Leaf $value) {}
}
class ScalarParameter
{
    public function __construct(string $value = 'default') {}
}
class UntypedParameter
{
    public function __construct($value) {}
}
class VariadicParameter
{
    public function __construct(Leaf ...$value) {}
}
class UnionParameter
{
    public function __construct(Leaf|Branch $value) {}
}
interface Contract {}
interface OtherContract {}
class IntersectionParameter
{
    public function __construct(Contract&OtherContract $value) {}
}
class ObjectParameter
{
    public function __construct(object $value) {}
}
class MixedParameter
{
    public function __construct(mixed $value) {}
}
class InterfaceParameter
{
    public function __construct(Contract $value) {}
}
abstract class AbstractService {}
class AbstractParameter
{
    public function __construct(AbstractService $value) {}
}
class PrivateConstructor
{
    private function __construct() {}
}
class ProtectedConstructor
{
    protected function __construct() {}
}
class CycleA
{
    public function __construct(CycleB $value) {}
}
class CycleB
{
    public function __construct(CycleA $value) {}
}
class SelfParameter
{
    public function __construct(self $value) {}
}
class InheritedSelfParameter extends SelfParameter {}
class ParentParameter extends Leaf
{
    public function __construct(public parent $value) {}
}
class InheritedParentParameter extends ParentParameter {}
class FailingService
{
    public static bool $fail = true;
    public static ?Leaf $seen = null;

    public function __construct(public Leaf $leaf)
    {
        self::$seen = $leaf;
        if (self::$fail) {
            throw new LogicException('deliberate failure');
        }
    }
}
