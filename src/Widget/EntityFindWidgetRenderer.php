<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Widget;

use CoolMS\Dtmpl\Runtime\EntityWrapperFactory;
use CoolMS\Dtmpl\Widget\WidgetRendererInterface;
use CoolMS\Entity\Resolver\EntityAliasResolverInterface;
use CoolMS\Entity\Security\NoRecordIsReadable;
use CoolMS\Entity\Security\RecordReadGuardInterface;
use Stringable;

/**
 * `{widget:entity:find alias=`x` filter=`...`}` -- resolves a single
 * entity by alias and optional RQL filter and returns it wrapped in
 * an `EntityWrapper` so DTMPL templates can navigate its properties
 * via `{var:name.prop}`.
 *
 * The wrapper is the read guard's: it reads only the fields the guard
 * allows for the record, and a related record a template steps into
 * is asked of the same guard ({@see EntityWrapperFactory::wrap()}).
 *
 * Returns `null` when the alias parameter is missing or non-string,
 * when the resolver yields no entity or the guard refuses it, or when
 * the filter parameter is non-string. Malformed RQL syntax bubbles up
 * as a parser exception -- developer error rather than runtime
 * fallback -- and so does a filter or sort on a field the guard does
 * not allow.
 */
final class EntityFindWidgetRenderer implements WidgetRendererInterface
{
    /** Registry key. A constant so WidgetRegistryPass can read it at compile time, without building this renderer. */
    public const string KEY = 'entity:find';

    public string $key { get => self::KEY; }

    public function __construct(
        private readonly EntityAliasResolverInterface $resolver,
        private readonly EntityWrapperFactory $wrapperFactory,
        private readonly RecordReadGuardInterface $guard = new NoRecordIsReadable(),
    ) {
    }

    public function __invoke(array $context, array $params = []): ?Stringable
    {
        $alias = $params['alias'] ?? null;
        if (!is_string($alias) || '' === $alias) {
            return null;
        }
        $filter = $params['filter'] ?? null;
        if (null !== $filter && !is_string($filter)) {
            return null;
        }
        $entity = $this->resolver->find($alias, $filter);
        if (null === $entity) {
            return null;
        }

        return $this->wrapperFactory->wrap($entity, $this->guard->fieldsFor(...));
    }
}
