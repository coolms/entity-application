<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Tests\Widget;

use CoolMS\Dtmpl\Runtime\EntityCollection;
use CoolMS\Dtmpl\Runtime\EntityWrapper;
use CoolMS\Dtmpl\Runtime\EntityWrapperFactory;
use CoolMS\Entity\Application\Tests\Fixture\GrantsTheseFields;
use CoolMS\Entity\Application\Widget\EntityFindAllWidgetRenderer;
use CoolMS\Entity\Resolver\EntityAliasResolverInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Unit tests for the `entity:findAll` widget renderer.
 */
final class EntityFindAllWidgetRendererTest extends TestCase
{
    public function testReturnsNullWhenAliasParamMissingOrInvalid(): void
    {
        $renderer = $this->makeRenderer($this->createStub(EntityAliasResolverInterface::class));

        self::assertNull($renderer([]));
        self::assertNull($renderer([], ['alias' => '']));
        self::assertNull($renderer([], ['alias' => 123]));
    }

    public function testReturnsEmptyCollectionWhenResolverFindsNothing(): void
    {
        $resolver = $this->createStub(EntityAliasResolverInterface::class);
        $resolver->method('findAll')->willReturn([]);
        $renderer = $this->makeRenderer($resolver);

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
        $renderer = $this->makeRenderer($resolver);

        $result = $renderer([], ['alias' => 'users']);

        self::assertInstanceOf(EntityCollection::class, $result);
        self::assertCount(2, $result);
        $items = iterator_to_array($result);
        self::assertContainsOnlyInstancesOf(EntityWrapper::class, $items);
        self::assertSame([$a, $b], array_map(static fn (EntityWrapper $w): object => $w->entity(), $items));
    }

    public function testPassesFilterToResolverWhenProvided(): void
    {
        $resolver = $this->createMock(EntityAliasResolverInterface::class);
        $resolver->expects(self::once())
            ->method('findAll')
            ->with('users', 'filter=status eq active&sort=-createdAt')
            ->willReturn([]);
        $renderer = $this->makeRenderer($resolver);

        $renderer([], ['alias' => 'users', 'filter' => 'filter=status eq active&sort=-createdAt']);
    }

    private function makeRenderer(EntityAliasResolverInterface $resolver): EntityFindAllWidgetRenderer
    {
        return new EntityFindAllWidgetRenderer(
            $resolver,
            new EntityWrapperFactory(PropertyAccess::createPropertyAccessor()),
            new GrantsTheseFields(fields: ['id']),
        );
    }
}
