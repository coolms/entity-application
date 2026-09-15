<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application\Tests;

use CoolMS\Entity\Application\ReflectionFieldExtractor;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Stringable;
use Symfony\Component\Uid\Uuid;

final class ReflectionFieldExtractorTest extends TestCase
{
    public function testExtractsPublicPropertiesAndScalars(): void
    {
        $entity = new ExtractFixtureSimple('Alice', 30, true);
        $ext = new ReflectionFieldExtractor();
        $out = $ext->extract($entity);

        self::assertSame('Alice', $out['name']);
        self::assertSame(30, $out['age']);
        self::assertTrue($out['active']);
    }

    public function testGetterMethodsExposedAsProperties(): void
    {
        $entity = new ExtractFixtureWithGetters();
        $ext = new ReflectionFieldExtractor();
        $out = $ext->extract($entity);

        self::assertSame('Bob', $out['displayName']);
        self::assertTrue($out['active']);     // isActive
        self::assertTrue($out['perms']);      // hasPerms
    }

    public function testFieldsAllowListFiltersOutput(): void
    {
        $entity = new ExtractFixtureSimple('Alice', 30, true);
        $ext = new ReflectionFieldExtractor();
        $out = $ext->extract($entity, ['name']);

        self::assertSame(['name' => 'Alice'], $out);
    }

    public function testUuidFlattenedToRfc4122(): void
    {
        $uuid = Uuid::v7();
        $entity = new ExtractFixtureWithUuid($uuid);
        $ext = new ReflectionFieldExtractor();
        $out = $ext->extract($entity);

        self::assertSame($uuid->toRfc4122(), $out['id']);
    }

    public function testDateTimeFlattenedToAtom(): void
    {
        $when = new DateTimeImmutable('2026-05-12T10:00:00+00:00');
        $entity = new ExtractFixtureWithDate($when);
        $ext = new ReflectionFieldExtractor();
        $out = $ext->extract($entity);

        self::assertSame('2026-05-12T10:00:00+00:00', $out['createdAt']);
    }

    public function testNonScalarObjectsSkipped(): void
    {
        $entity = new ExtractFixtureWithNestedObject();
        $ext = new ReflectionFieldExtractor();
        $out = $ext->extract($entity);

        // `name` is a public scalar -- included
        self::assertArrayHasKey('name', $out);
        // `nested` holds a stdClass -- skipped
        self::assertArrayNotHasKey('nested', $out);
    }

    public function testStringableLabelPreferred(): void
    {
        $entity = new ExtractFixtureStringable('Customer #42');
        $ext = new ReflectionFieldExtractor();
        self::assertSame('Customer #42', $ext->extractLabel($entity));
    }

    public function testLabelFallsBackToGetters(): void
    {
        $entity = new ExtractFixtureWithGetters();
        $ext = new ReflectionFieldExtractor();
        // getDisplayName beats getName, etc.
        self::assertSame('Bob', $ext->extractLabel($entity));
    }

    public function testLabelFinalFallback(): void
    {
        $entity = new ExtractFixtureWithUuid(Uuid::v7());
        $ext = new ReflectionFieldExtractor();
        // No __toString, no label getter -- class basename + id
        self::assertStringContainsString('ExtractFixtureWithUuid', $ext->extractLabel($entity));
    }

    public function testSecondaryFromEmailGetter(): void
    {
        $entity = new ExtractFixtureWithSecondary();
        $ext = new ReflectionFieldExtractor();
        self::assertSame('alice@example.com', $ext->extractSecondary($entity));
    }

    public function testSecondaryReturnsNullWhenNoMatch(): void
    {
        $entity = new ExtractFixtureSimple('Alice', 30, true);
        $ext = new ReflectionFieldExtractor();
        self::assertNull($ext->extractSecondary($entity));
    }

    public function testExtractIdViaGetId(): void
    {
        $uuid = Uuid::v7();
        $entity = new ExtractFixtureWithUuid($uuid);
        $ext = new ReflectionFieldExtractor();
        self::assertSame($uuid->toRfc4122(), $ext->extractId($entity));
    }

    public function testExtractIdViaPublicProperty(): void
    {
        $entity = new ExtractFixtureWithPublicId('abc-123');
        $ext = new ReflectionFieldExtractor();
        self::assertSame('abc-123', $ext->extractId($entity));
    }

    public function testExtractIdThrowsWhenAbsent(): void
    {
        $entity = new ExtractFixtureSimple('Alice', 30, true);
        $ext = new ReflectionFieldExtractor();
        $this->expectException(RuntimeException::class);
        $ext->extractId($entity);
    }
}

// ---- Test fixtures ---------------------------------------------------

final class ExtractFixtureSimple
{
    public function __construct(
        public string $name,
        public int $age,
        public bool $active,
    ) {
    }
}

final class ExtractFixtureWithGetters
{
    public function getDisplayName(): string
    {
        return 'Bob';
    }

    public function isActive(): bool
    {
        return true;
    }

    public function hasPerms(): bool
    {
        return true;
    }

    public function setSomething(string $s): void
    { /* skipped: non-getter */
    }
}

final class ExtractFixtureWithUuid
{
    public function __construct(public Uuid $id)
    {
    }
}

final class ExtractFixtureWithDate
{
    public function __construct(public DateTimeImmutable $createdAt)
    {
    }
}

final class ExtractFixtureWithNestedObject
{
    public string $name = 'foo';
    public stdClass $nested;

    public function __construct()
    {
        $this->nested = new stdClass();
    }
}

final class ExtractFixtureStringable implements Stringable
{
    public function __construct(private readonly string $label)
    {
    }

    public function __toString(): string
    {
        return $this->label;
    }
}

final class ExtractFixtureWithSecondary
{
    public function getName(): string
    {
        return 'Alice';
    }

    public function getEmail(): string
    {
        return 'alice@example.com';
    }
}

final class ExtractFixtureWithPublicId
{
    public function __construct(public string $id)
    {
    }
}
