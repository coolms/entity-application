<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Tests\Resolver;

use ArrayObject;
use CoolMS\Entity\Application\Resolver\PredicateNotAllowed;
use CoolMS\Entity\Application\Resolver\RepositoryEntityAliasResolver;
use CoolMS\Entity\Application\Tests\Fixture\GrantsTheseFields;
use CoolMS\Entity\Registry\EntityAliasRegistryInterface;
use CoolMS\Entity\Registry\RepositoryRegistryInterface;
use CoolMS\Entity\Security\RecordReadGuardInterface;
use CoolMS\Rql\RqlParser;
use CoolMS\Rql\RqlRepositoryInterface;
use CoolMS\Rql\RqlResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * What a template's `entity:find` / `entity:findAll` may read is the read guard's to decide: a record it refuses is
 * left out, exactly as one that does not match, and a filter or sort on a field it does not let a predicate use is
 * refused before the query runs, naming the field and never a value. With no guard, nothing is readable.
 */
final class AnAliasReadAsksTheReadGuardTest extends TestCase
{
    #[Test]
    public function aResolverGivenNoGuardReadsNoRecord(): void
    {
        $resolver = new RepositoryEntityAliasResolver(
            $this->aliases(),
            $this->repositories($this->repositoryOf([new stdClass()])),
            new RqlParser(),
        );

        self::assertNull($resolver->find('invoice'));
        self::assertSame([], $resolver->findAll('invoice'));
    }

    #[Test]
    public function aRecordTheGuardRefusesIsLeftOutAsIfItDidNotMatch(): void
    {
        $granted = new stdClass();
        $refused = new ArrayObject();
        $guard = new GrantsTheseFields(refused: [ArrayObject::class]);

        self::assertSame([$granted], $this->resolver($this->repositoryOf([$refused, $granted]), $guard)->findAll('invoice'));
        self::assertNull($this->resolver($this->repositoryOf([$refused]), $guard)->find('invoice'));
        self::assertSame($granted, $this->resolver($this->repositoryOf([$granted]), $guard)->find('invoice'));
    }

    #[Test]
    public function aFilterOnAFieldThePredicateMayNotUseIsRefusedBeforeTheQueryRuns(): void
    {
        $guard = new GrantsTheseFields(predicates: ['id', 'title']);

        foreach ([
            'filter=secret eq "x9-guess"' => 'secret',
            'filter=title cn "a"|secret cn "x9-guess"' => 'secret',
            'filter=id eq 1&sort=-createdAt' => 'createdAt',
            'filter=extras.salary gt 100' => 'extras.salary',
        ] as $rql => $field) {
            foreach (['find', 'findAll'] as $method) {
                $repository = $this->createMock(RqlRepositoryInterface::class);
                $repository->expects(self::never())->method('findByRql');
                try {
                    $this->resolver($repository, $guard)->{$method}('invoice', $rql);
                    self::fail($method . ' ' . $rql . ': not refused');
                } catch (PredicateNotAllowed $e) {
                    self::assertStringContainsString('"' . $field . '"', $e->getMessage(), $rql);
                    self::assertStringNotContainsString('x9-guess', $e->getMessage(), 'the value is never named');
                    self::assertStringNotContainsString('100', $e->getMessage());
                }
            }
        }
    }

    #[Test]
    public function aFilterAndSortOnAllowedFieldsRun(): void
    {
        $record = new stdClass();
        $guard = new GrantsTheseFields(predicates: ['id', 'title', 'createdAt']);

        self::assertSame(
            [$record],
            $this->resolver($this->repositoryOf([$record]), $guard)->findAll('invoice', 'filter=title cn "a"|id eq 1&sort=-createdAt'),
        );
    }

    private function resolver(RqlRepositoryInterface $repository, RecordReadGuardInterface $guard): RepositoryEntityAliasResolver
    {
        return new RepositoryEntityAliasResolver($this->aliases(), $this->repositories($repository), new RqlParser(), $guard);
    }

    private function aliases(): EntityAliasRegistryInterface
    {
        $aliases = $this->createStub(EntityAliasRegistryInterface::class);
        $aliases->method('resolve')->willReturn('Acme\\Invoice');

        return $aliases;
    }

    private function repositories(RqlRepositoryInterface $repository): RepositoryRegistryInterface
    {
        $registry = $this->createStub(RepositoryRegistryInterface::class);
        $registry->method('has')->willReturn(true);
        $registry->method('get')->willReturn($repository);

        return $registry;
    }

    /** @param list<object> $records */
    private function repositoryOf(array $records): RqlRepositoryInterface
    {
        $repository = $this->createStub(RqlRepositoryInterface::class);
        $repository->method('findByRql')->willReturn(new RqlResult($records, count($records), 1, 200));

        return $repository;
    }
}
