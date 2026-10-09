<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Resolver;

use CoolMS\Entity\Registry\EntityAliasRegistryInterface;
use CoolMS\Entity\Registry\RepositoryRegistryInterface;
use CoolMS\Entity\Resolver\EntityAliasResolverInterface;
use CoolMS\Entity\Security\NoRecordIsReadable;
use CoolMS\Entity\Security\RecordReadGuardInterface;
use CoolMS\Rql\AndNode;
use CoolMS\Rql\FilterNode;
use CoolMS\Rql\OrNode;
use CoolMS\Rql\RqlContext;
use CoolMS\Rql\RqlParser;
use CoolMS\Rql\RqlQuery;

use function array_filter;
use function array_values;
use function in_array;

/**
 * Concrete `EntityAliasResolverInterface` that fetches entities
 * through the local repository registry.
 *
 * The optional RQL filter string is parsed with the project's
 * `RqlParser` (URL-query format, e.g., `filter=status eq "ready"`)
 * and the resulting `RqlQuery` is passed directly to the
 * repository's `findByRql`. Every RQL operator the parser supports
 * propagates to the underlying repository query unchanged.
 *
 * Returns `null` / `[]` when the alias is unknown or the entity's
 * class has no registered repository, so widget callers can treat
 * absence uniformly.
 *
 * What a template may read is the {@see RecordReadGuardInterface}'s
 * to decide, asked twice:
 *   - before the query runs, every field the filter or the sort names
 *     must be one the guard lets a predicate use for that class
 *     (`predicateFieldsFor()`, matched exactly as written, so
 *     `extras.color` must be listed as such), or
 *     {@see PredicateNotAllowed} is thrown, naming the field and never
 *     a value;
 *   - after it, a record the guard refuses is left out: `find()`
 *     answers null, exactly as for no match, and `findAll()` drops it.
 * The limit applies before the guard: `find()` answers null when its
 * one match is refused, even if a later record would be readable, and
 * `findAll()` can return fewer records than its limit.
 * With no guard given, none is readable and no predicate is allowed.
 */
final readonly class RepositoryEntityAliasResolver implements EntityAliasResolverInterface
{
    /** Effective unbounded limit for `findAll`; matches RqlQuery::MAX_LIMIT. */
    private const int FIND_ALL_DEFAULT_LIMIT = RqlQuery::MAX_LIMIT;

    /** QueryBuilder alias used when the resolver builds an RqlContext on its own. */
    private const string DEFAULT_ENTITY_ALIAS = 'e';

    public function __construct(
        private EntityAliasRegistryInterface $aliasRegistry,
        private RepositoryRegistryInterface $repositories,
        private RqlParser $rqlParser,
        private RecordReadGuardInterface $guard = new NoRecordIsReadable(),
    ) {
    }

    public function find(string $alias, ?string $rqlFilter = null): ?object
    {
        $fqcn = $this->aliasRegistry->resolve($alias);
        if (null === $fqcn || !$this->repositories->has($fqcn)) {
            return null;
        }
        $query = $this->buildRqlQuery($rqlFilter, forcedLimit: 1);
        $this->refuseUnallowedPredicates($alias, $fqcn, $query);
        $record = $this->repositories->get($fqcn)->findByRql($query, $this->context())->items[0] ?? null;

        return null !== $record && null !== $this->guard->fieldsFor($record) ? $record : null;
    }

    public function findAll(string $alias, ?string $rqlFilter = null): array
    {
        $fqcn = $this->aliasRegistry->resolve($alias);
        if (null === $fqcn || !$this->repositories->has($fqcn)) {
            return [];
        }
        $query = $this->buildRqlQuery($rqlFilter, defaultLimit: self::FIND_ALL_DEFAULT_LIMIT);
        $this->refuseUnallowedPredicates($alias, $fqcn, $query);
        $records = $this->repositories->get($fqcn)->findByRql($query, $this->context())->items;

        return array_values(array_filter($records, fn (object $record): bool => null !== $this->guard->fieldsFor($record)));
    }

    /** @param class-string $fqcn */
    private function refuseUnallowedPredicates(string $alias, string $fqcn, RqlQuery $query): void
    {
        $fields = $this->filterFields($query->filters);
        foreach ($query->sort as $sort) {
            $fields[] = $sort->field;
        }
        if ([] === $fields) {
            return;
        }
        $allowed = $this->guard->predicateFieldsFor($fqcn);
        foreach ($fields as $field) {
            if (!in_array($field, $allowed, true)) {
                throw PredicateNotAllowed::field($alias, $field);
            }
        }
    }

    /**
     * @param array<FilterNode|OrNode|AndNode> $nodes
     *
     * @return list<string>
     */
    private function filterFields(array $nodes): array
    {
        $fields = [];
        foreach ($nodes as $node) {
            if ($node instanceof FilterNode) {
                $fields[] = $node->field;
                continue;
            }
            foreach ($this->filterFields($node->nodes) as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    private function buildRqlQuery(
        ?string $rqlFilter,
        ?int $forcedLimit = null,
        int $defaultLimit = RqlQuery::DEFAULT_LIMIT,
    ): RqlQuery {
        if (null === $rqlFilter || '' === $rqlFilter) {
            return new RqlQuery(limit: $forcedLimit ?? $defaultLimit);
        }
        $parsed = $this->rqlParser->parse($rqlFilter);

        return new RqlQuery(
            filters: $parsed->filters,
            sort: $parsed->sort,
            page: $parsed->page,
            limit: $forcedLimit ?? $parsed->limit,
        );
    }

    private function context(): RqlContext
    {
        return new RqlContext(self::DEFAULT_ENTITY_ALIAS);
    }
}
