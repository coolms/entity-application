<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Tests\Widget;

use CoolMS\Dtmpl\Runtime\EntityCollection;
use CoolMS\Entity\Application\Widget\EntityFindAllWidgetRenderer;
use CoolMS\Entity\Resolver\EntityAliasResolverInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the `entity:findAll` widget renderer.
 */
final class EntityFindAllWidgetRendererTest extends TestCase
{
    public function testReturnsNullWhenAliasParamMissingOrInvalid(): void
    {
        $renderer = new EntityFindAllWidgetRenderer($this->createStub(EntityAliasResolverInterface::class));

        self::assertNull($renderer([]));
        self::assertNull($renderer([], ['alias' => '']));
        self::assertNull($renderer([], ['alias' => 123]));
    }

    public function testReturnsEmptyCollectionWhenResolverFindsNothing(): void
    {
        $resolver = $this->createStub(EntityAliasResolverInterface::class);
        $resolver->method('findAll')->willReturn([]);
        $renderer = new EntityFindAllWidgetRenderer($resolver);

        $result = $renderer([], ['alias' => 'users']);

        self::assertInstanceOf(EntityCollection::class, $result);
        self::assertCount(0, $result);
    }

    public function testWrapsResultsInCollection(): void
    {
        $a = new class {
            public int $id = 1;
        };
        $b = new class {
            public int $id = 2;
        };
        $resolver = $this->createStub(EntityAliasResolverInterface::class);
        $resolver->method('findAll')->willReturn([$a, $b]);
        $renderer = new EntityFindAllWidgetRenderer($resolver);

        $result = $renderer([], ['alias' => 'users']);

        self::assertInstanceOf(EntityCollection::class, $result);
        self::assertCount(2, $result);
        self::assertSame([$a, $b], iterator_to_array($result));
    }

    public function testPassesFilterToResolverWhenProvided(): void
    {
        $resolver = $this->createMock(EntityAliasResolverInterface::class);
        $resolver->expects(self::once())
            ->method('findAll')
            ->with('users', 'filter=status eq active&sort=-createdAt')
            ->willReturn([]);
        $renderer = new EntityFindAllWidgetRenderer($resolver);

        $renderer([], ['alias' => 'users', 'filter' => 'filter=status eq active&sort=-createdAt']);
    }
}
