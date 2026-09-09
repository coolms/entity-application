<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Widget;

use CoolMS\Dtmpl\Runtime\EntityCollection;
use CoolMS\Dtmpl\Widget\WidgetRendererInterface;
use CoolMS\Entity\Resolver\EntityAliasResolverInterface;
use Stringable;

/**
 * `{widget:entity:findAll alias=`x` filter=`...`}` -- resolves a list
 * of entities by alias and optional RQL filter and returns them in
 * an `EntityCollection` so DTMPL `{loop:items:item}` can iterate the
 * raw entities directly.
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

        return new EntityCollection($this->resolver->findAll($alias, $filter));
    }
}
