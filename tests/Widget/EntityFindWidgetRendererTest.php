<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Tests\Widget;

use CoolMS\Dtmpl\Runtime\EntityWrapper;
use CoolMS\Dtmpl\Runtime\EntityWrapperFactory;
use CoolMS\Entity\Application\Widget\EntityFindWidgetRenderer;
use CoolMS\Entity\Resolver\EntityAliasResolverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Unit tests for the `entity:find` widget renderer.
 */
final class EntityFindWidgetRendererTest extends TestCase
{
    public function testReturnsNullWhenAliasParamMissingOrInvalid(): void
    {
        $renderer = $this->makeRenderer($this->stubResolver());

        self::assertNull($renderer([]));
        self::assertNull($renderer([], ['alias' => '']));
        self::assertNull($renderer([], ['alias' => 123]));
    }

    public function testReturnsNullWhenResolverFindsNothing(): void
    {
        $resolver = $this->createStub(EntityAliasResolverInterface::class);
        $resolver->method('find')->willReturn(null);
        $renderer = $this->makeRenderer($resolver);

        self::assertNull($renderer([], ['alias' => 'invoice', 'filter' => 'filter=id eq 999']));
    }

    public function testWrapsEntityWhenResolverFindsOne(): void
    {
        $entity = new class {
            public string $name = 'Alice';
        };
        $resolver = $this->createMock(EntityAliasResolverInterface::class);
        $resolver->expects(self::once())
            ->method('find')
            ->with('user', 'filter=id eq 1')
            ->willReturn($entity);
        $renderer = $this->makeRenderer($resolver);

        $result = $renderer([], ['alias' => 'user', 'filter' => 'filter=id eq 1']);

        self::assertInstanceOf(EntityWrapper::class, $result);
        self::assertSame('Alice', $result->__get('name'));
    }

    public function testPassesNullFilterWhenFilterParamMissing(): void
    {
        $entity = new class {
        };
        $resolver = $this->createMock(EntityAliasResolverInterface::class);
        $resolver->expects(self::once())
            ->method('find')
            ->with('user', null)
            ->willReturn($entity);
        $renderer = $this->makeRenderer($resolver);

        $renderer([], ['alias' => 'user']);
    }

    private function stubResolver(): EntityAliasResolverInterface
    {
        return $this->createStub(EntityAliasResolverInterface::class);
    }

    private function makeRenderer(EntityAliasResolverInterface $resolver): EntityFindWidgetRenderer
    {
        return new EntityFindWidgetRenderer(
            $resolver,
            new EntityWrapperFactory(PropertyAccess::createPropertyAccessor()),
        );
    }
}
