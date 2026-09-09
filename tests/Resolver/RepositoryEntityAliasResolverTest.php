<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Tests\Resolver;

use CoolMS\Entity\Registry\EntityAliasRegistryInterface;
use CoolMS\Entity\Registry\RepositoryRegistryInterface;
use CoolMS\Entity\Application\Resolver\RepositoryEntityAliasResolver;
use CoolMS\Rql\FilterNode;
use CoolMS\Rql\FilterOp;
use CoolMS\Rql\RqlParser;
use CoolMS\Rql\RqlQuery;
use CoolMS\Rql\RqlRepositoryInterface;
use CoolMS\Rql\RqlResult;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for the repository-backed entity alias resolver.
 */
final class RepositoryEntityAliasResolverTest extends TestCase
{
    public function testFindReturnsNullForUnknownAlias(): void
    {
        $resolver = $this->makeResolver(aliasMap: []);

        self::assertNull($resolver->find('unknown'));
    }

    public function testFindReturnsNullWhenNoRepositoryRegistered(): void
    {
        $resolver = $this->makeResolver(
            aliasMap: ['invoice' => 'Acme\\Invoice'],
            repositories: [],
        );

        self::assertNull($resolver->find('invoice'));
    }

    public function testFindNoFilterReturnsFirstRepoResult(): void
    {
        $entity = new stdClass();
        $repo = $this->createMock(RqlRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findByRql')
            ->with(self::callback(function (RqlQuery $query): bool {
                self::assertSame(1, $query->limit);
                self::assertSame([], $query->filters);

                return true;
            }))
            ->willReturn(new RqlResult([$entity], 1, 1, 1));

        $resolver = $this->makeResolver(
            aliasMap: ['invoice' => 'Acme\\Invoice'],
            repositories: ['Acme\\Invoice' => $repo],
        );

        self::assertSame($entity, $resolver->find('invoice'));
    }

    public function testFindReturnsNullWhenRepositoryYieldsNoItems(): void
    {
        $repo = $this->createStub(RqlRepositoryInterface::class);
        $repo->method('findByRql')->willReturn(new RqlResult([], 0, 1, 1));

        $resolver = $this->makeResolver(
            aliasMap: ['invoice' => 'Acme\\Invoice'],
            repositories: ['Acme\\Invoice' => $repo],
        );

        self::assertNull($resolver->find('invoice', 'filter=id eq 999'));
    }

    public function testFindWithFilterForwardsFullRqlToRepo(): void
    {
        $entity = new stdClass();
        $repo = $this->createMock(RqlRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findByRql')
            ->with(self::callback(function (RqlQuery $query): bool {
                self::assertCount(1, $query->filters);
                $node = $query->filters[0];
                self::assertInstanceOf(FilterNode::class, $node);
                self::assertSame('id', $node->field);
                self::assertSame(FilterOp::Eq, $node->op);
                self::assertSame(42, $node->value);
                self::assertSame(1, $query->limit);

                return true;
            }))
            ->willReturn(new RqlResult([$entity], 1, 1, 1));

        $resolver = $this->makeResolver(
            aliasMap: ['invoice' => 'Acme\\Invoice'],
            repositories: ['Acme\\Invoice' => $repo],
        );

        self::assertSame($entity, $resolver->find('invoice', 'filter=id eq 42'));
    }

    public function testFindWithNonEqFilterPassesThroughUnchanged(): void
    {
        // Phase 2b dropped non-eq operators on the way through SearchCriteria.
        // After the RQL-native migration the full filter survives to the repo.
        $repo = $this->createMock(RqlRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findByRql')
            ->with(self::callback(function (RqlQuery $query): bool {
                self::assertCount(1, $query->filters);
                $node = $query->filters[0];
                self::assertInstanceOf(FilterNode::class, $node);
                self::assertSame(FilterOp::Gt, $node->op);
                self::assertSame('amount', $node->field);

                return true;
            }))
            ->willReturn(new RqlResult([], 0, 1, 1));

        $resolver = $this->makeResolver(
            aliasMap: ['invoice' => 'Acme\\Invoice'],
            repositories: ['Acme\\Invoice' => $repo],
        );

        $resolver->find('invoice', 'filter=amount gt 100');
    }

    public function testFindAllReturnsEmptyForUnknownAlias(): void
    {
        $resolver = $this->makeResolver(aliasMap: []);

        self::assertSame([], $resolver->findAll('unknown'));
    }

    public function testFindAllReturnsEmptyWhenNoRepositoryRegistered(): void
    {
        $resolver = $this->makeResolver(
            aliasMap: ['invoice' => 'Acme\\Invoice'],
            repositories: [],
        );

        self::assertSame([], $resolver->findAll('invoice'));
    }

    public function testFindAllReturnsArrayOfResults(): void
    {
        $a = new stdClass();
        $b = new stdClass();
        $repo = $this->createStub(RqlRepositoryInterface::class);
        $repo->method('findByRql')->willReturn(new RqlResult([$a, $b], 2, 1, 200));

        $resolver = $this->makeResolver(
            aliasMap: ['invoice' => 'Acme\\Invoice'],
            repositories: ['Acme\\Invoice' => $repo],
        );

        self::assertSame([$a, $b], $resolver->findAll('invoice'));
    }

    public function testFindAllReturnsEmptyWhenRepositoryYieldsNothing(): void
    {
        $repo = $this->createStub(RqlRepositoryInterface::class);
        $repo->method('findByRql')->willReturn(new RqlResult([], 0, 1, 200));

        $resolver = $this->makeResolver(
            aliasMap: ['invoice' => 'Acme\\Invoice'],
            repositories: ['Acme\\Invoice' => $repo],
        );

        self::assertSame([], $resolver->findAll('invoice'));
    }

    public function testFindAllUsesHighDefaultLimitWhenNoFilter(): void
    {
        $repo = $this->createMock(RqlRepositoryInterface::class);
        $repo->expects(self::once())
            ->method('findByRql')
            ->with(self::callback(function (RqlQuery $query): bool {
                self::assertGreaterThan(20, $query->limit);

                return true;
            }))
            ->willReturn(new RqlResult([], 0, 1, 200));

        $resolver = $this->makeResolver(
            aliasMap: ['invoice' => 'Acme\\Invoice'],
            repositories: ['Acme\\Invoice' => $repo],
        );
        $resolver->findAll('invoice');
    }

    public function testResolverAcceptsCollectionAlias(): void
    {
        $entity = new stdClass();
        $repo = $this->createStub(RqlRepositoryInterface::class);
        $repo->method('findByRql')->willReturn(new RqlResult([$entity], 1, 1, 200));

        // Same FQCN reachable via either alias form.
        $resolver = $this->makeResolver(
            aliasMap: ['invoice' => 'Acme\\Invoice', 'invoices' => 'Acme\\Invoice'],
            repositories: ['Acme\\Invoice' => $repo],
        );

        self::assertSame([$entity], $resolver->findAll('invoices'));
        self::assertSame($entity, $resolver->find('invoices'));
    }

    /**
     * @param array<string, class-string|string>    $aliasMap
     * @param array<string, RqlRepositoryInterface> $repositories
     */
    private function makeResolver(array $aliasMap, array $repositories = []): RepositoryEntityAliasResolver
    {
        $aliasRegistry = $this->createStub(EntityAliasRegistryInterface::class);
        $aliasRegistry->method('resolve')->willReturnCallback(
            static fn (string $alias): ?string => $aliasMap[$alias] ?? null,
        );

        $repoRegistry = $this->createStub(RepositoryRegistryInterface::class);
        $repoRegistry->method('has')->willReturnCallback(
            static fn (string $fqcn): bool => isset($repositories[$fqcn]),
        );
        $repoRegistry->method('get')->willReturnCallback(
            static fn (string $fqcn): RqlRepositoryInterface => $repositories[$fqcn],
        );

        return new RepositoryEntityAliasResolver(
            $aliasRegistry,
            $repoRegistry,
            new RqlParser(),
        );
    }
}
