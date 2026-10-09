<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Tests\Widget;

use CoolMS\Dtmpl\DtmplEngine;
use CoolMS\Dtmpl\Runtime\EntityWrapperFactory;
use CoolMS\Dtmpl\Widget\WidgetRegistry;
use CoolMS\Entity\Application\Tests\Fixture\GrantsTheseFields;
use CoolMS\Entity\Application\Tests\Fixture\Invoice;
use CoolMS\Entity\Application\Widget\EntityFindAllWidgetRenderer;
use CoolMS\Entity\Application\Widget\EntityFindWidgetRenderer;
use CoolMS\Entity\Resolver\EntityAliasResolverInterface;
use CoolMS\Entity\Security\RecordReadGuardInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * A template reads, through `entity:find` and `entity:findAll`, only the fields the read guard allows: a field it
 * does not allow prints nothing, and so does a field of a related record the guard refuses, however the template
 * reaches it.
 */
final class AWidgetReadsOnlyWhatTheGuardAllowsTest extends TestCase
{
    #[Test]
    public function aFieldTheGuardDoesNotAllowPrintsNothing(): void
    {
        $template = '{def:invoice=widget:entity:find alias=`invoice`}'
            . 'Total: {var:invoice.total}; note: [{var:invoice.note}]; owner: [{var:invoice.owner.email}]';

        self::assertSame(
            'Total: 250; note: []; owner: []',
            $this->engine(new GrantsTheseFields(fields: ['total', 'owner']))->render($template),
        );
    }

    #[Test]
    public function eachListedRecordReadsOnlyItsAllowedFields(): void
    {
        $template = '{def:invoices=widget:entity:findAll alias=`invoices`}'
            . '{loop:invoices:i}[{var:i.total}|{var:i.note}]{endloop}';

        self::assertSame('[250|][250|]', $this->engine(new GrantsTheseFields(fields: ['total']))->render($template));
    }

    #[Test]
    public function aRecordTheGuardRefusesIsNotRendered(): void
    {
        $template = '{def:invoice=widget:entity:find alias=`invoice`}{if:invoice}found{else}none{endif}';

        self::assertSame('none', $this->engine(new GrantsTheseFields(refused: [Invoice::class]))->render($template));
    }

    private function engine(RecordReadGuardInterface $guard): DtmplEngine
    {
        $resolver = $this->createStub(EntityAliasResolverInterface::class);
        $resolver->method('find')->willReturn(new Invoice());
        $resolver->method('findAll')->willReturn([new Invoice(), new Invoice()]);
        $factory = new EntityWrapperFactory(PropertyAccess::createPropertyAccessor());
        $registry = new WidgetRegistry();
        $registry->register(new EntityFindWidgetRenderer($resolver, $factory, $guard));
        $registry->register(new EntityFindAllWidgetRenderer($resolver, $factory, $guard));

        return new DtmplEngine(widgets: $registry);
    }
}
