<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Widget;

use CoolMS\Dtmpl\Runtime\EntityCollection;
use CoolMS\Dtmpl\Runtime\EntityWrapperFactory;
use CoolMS\Dtmpl\Widget\WidgetRendererInterface;
use CoolMS\Entity\Resolver\EntityAliasResolverInterface;
use CoolMS\Entity\Security\NoRecordIsReadable;
use CoolMS\Entity\Security\RecordReadGuardInterface;
use Stringable;

/**
 * `{widget:entity:findAll alias=`x` filter=`...`}` -- resolves a list
 * of entities by alias and optional RQL filter and returns them in
 * an `EntityCollection` so DTMPL `{loop:items:item}` can iterate them.
 *
 * Each entity is handed out wrapped by the read guard: an item reads
 * only the fields the guard allows for it, a related record a template
 * steps into is asked of the same guard, and a record the guard refuses
 * is left out ({@see EntityWrapperFactory::wrap()}).
 *
 * Always returns a Stringable (the collection); empty results still
 * produce a Countable-with-count-zero collection that `{if:items}`
 * treats as falsy via the executor's existing `isTruthy` rule.
 * Returns `null` only when parameter validation fails (missing or
 * non-string alias, non-string filter).
 */
final class EntityFindAllWidgetRenderer implements WidgetRendererInterface
{
    /** Registry key. A constant so WidgetRegistryPass can read it at compile time, without building this renderer. */
    public const string KEY = 'entity:findAll';

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
        $items = [];
        foreach ($this->resolver->findAll($alias, $filter) as $entity) {
            $wrapped = $this->wrapperFactory->wrap($entity, $this->guard->fieldsFor(...));
            if (null !== $wrapped) {
                $items[] = $wrapped;
            }
        }

        return new EntityCollection($items);
    }
}
