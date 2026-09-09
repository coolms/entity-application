<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Resolver;

use CoolMS\Entity\Registry\EntityAliasRegistryInterface;
use CoolMS\Entity\Registry\RepositoryRegistryInterface;
use CoolMS\Entity\Resolver\EntityAliasResolverInterface;
use CoolMS\Rql\RqlContext;
use CoolMS\Rql\RqlParser;
use CoolMS\Rql\RqlQuery;

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
    ) {
    }

    public function find(string $alias, ?string $rqlFilter = null): ?object
    {
        $fqcn = $this->aliasRegistry->resolve($alias);
        if (null === $fqcn || !$this->repositories->has($fqcn)) {
            return null;
        }
        $query = $this->buildRqlQuery($rqlFilter, forcedLimit: 1);
        $result = $this->repositories->get($fqcn)->findByRql($query, $this->context());

        return $result->items[0] ?? null;
    }

    public function findAll(string $alias, ?string $rqlFilter = null): array
    {
        $fqcn = $this->aliasRegistry->resolve($alias);
        if (null === $fqcn || !$this->repositories->has($fqcn)) {
            return [];
        }
        $query = $this->buildRqlQuery($rqlFilter, defaultLimit: self::FIND_ALL_DEFAULT_LIMIT);
        $result = $this->repositories->get($fqcn)->findByRql($query, $this->context());

        return $result->items;
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
