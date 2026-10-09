<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Resolver;

use InvalidArgumentException;

use function sprintf;

/**
 * A template's filter or sort named a field the read guard does not let a predicate use for that class. Thrown
 * before the query runs; the message names the alias and the field, never a value, so it tells the author what to
 * change and tells nobody what the records hold.
 */
final class PredicateNotAllowed extends InvalidArgumentException
{
    public static function field(string $alias, string $field): self
    {
        return new self(sprintf('A template may not filter or sort "%s" by "%s".', $alias, $field));
    }
}
