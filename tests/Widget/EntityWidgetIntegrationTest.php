<?php

declare(strict_types=1);

namespace CoolMS\EntityApp\Tests\Widget;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Runtime\EntityWrapperFactory;
use CoolMS\Dtmpl\Widget\WidgetRegistry;
use CoolMS\Entity\Resolver\EntityAliasResolverInterface;
use CoolMS\EntityApp\Widget\EntityFindAllWidgetRenderer;
use CoolMS\EntityApp\Widget\EntityFindWidgetRenderer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * End-to-end integration tests that drive both Entity widgets
 * through the DTMPL engine with a mocked `EntityAliasResolverInterface`.
 */
final class EntityWidgetIntegrationTest extends TestCase
{
    public function testTemplateRendersPropertiesFromFoundEntity(): void
    {
        $entity = new class {
            public string $name = 'Alice';

            public function getTotal(): int
            {
                return 250;
            }
        };
        $resolver = $this->createStub(EntityAliasResolverInterface::class);
        $resolver->method('find')->willReturn($entity);

        $engine = $this->makeEngineWith($resolver);
        $template = '{def:invoice=widget:entity:find alias=`invoice` filter=`filter=id eq 1`}'
                  . 'Name: {var:invoice.name}, Total: {var:invoice.total}';

        self::assertSame('Name: Alice, Total: 250', $engine->render($template));
    }

    public function testTemplateLoopsOverFindAllResults(): void
    {
        $a = new class {
            public string $name = 'A';
        };
        $b = new class {
            public string $name = 'B';
        };
        $resolver = $this->createStub(EntityAliasResolverInterface::class);
        $resolver->method('findAll')->willReturn([$a, $b]);

        $engine = $this->makeEngineWith($resolver);
        $template = '{def:users=widget:entity:findAll alias=`users`}'
                  . '{loop:users:user}- {var:user.name}{endloop}';

        self::assertSame('- A- B', $engine->render($template));
    }

    public function testTemplateBranchesOnFindNull(): void
    {
        $resolver = $this->createStub(EntityAliasResolverInterface::class);
        $resolver->method('find')->willReturn(null);

        $engine = $this->makeEngineWith($resolver);
        $template = '{def:invoice=widget:entity:find alias=`invoice` filter=`filter=id eq 999`}'
                  . '{if:invoice}Found{else}Not found{endif}';

        self::assertSame('Not found', $engine->render($template));
    }

    private function makeEngineWith(EntityAliasResolverInterface $resolver): DtmplEngine
    {
        $factory = new EntityWrapperFactory(PropertyAccess::createPropertyAccessor());
        $registry = new WidgetRegistry();
        $registry->register(new EntityFindWidgetRenderer($resolver, $factory));
        $registry->register(new EntityFindAllWidgetRenderer($resolver));

        return new DtmplEngine(widgets: $registry);
    }
}
