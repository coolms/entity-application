<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Tests\Fixture;

use CoolMS\Entity\Security\RecordReadGuardInterface;

use function in_array;

/**
 * A read guard for tests: every record readable with the same fields, except one of a refused class; the same
 * predicate fields for every class.
 */
final readonly class GrantsTheseFields implements RecordReadGuardInterface
{
    /**
     * @param list<string>       $fields
     * @param list<string>       $predicates
     * @param list<class-string> $refused
     */
    public function __construct(
        private array $fields = [],
        private array $predicates = [],
        private array $refused = [],
    ) {
    }

    public function fieldsFor(object $record): ?array
    {
        return in_array($record::class, $this->refused, true) ? null : $this->fields;
    }

    public function predicateFieldsFor(string $class): array
    {
        return $this->predicates;
    }
}
