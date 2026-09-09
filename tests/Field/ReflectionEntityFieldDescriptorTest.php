<?php

declare(strict_types=1);

namespace CoolMS\EntityApp\Tests\Field;

use CoolMS\Core\Attribute\FieldMeta;
use CoolMS\EntityApp\Field\ReflectionEntityFieldDescriptor;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Reflection-based EntityFieldDescriptor.
 */
final class ReflectionEntityFieldDescriptorTest extends TestCase
{
    public function testDescribeReturnsOnlyAnnotatedFilterableFields(): void
    {
        $descriptors = new ReflectionEntityFieldDescriptor()->describe(StubEntity::class);

        $names = array_map(fn ($d) => $d->field, $descriptors);
        // `internal` is private:true -> skipped.
        // `notAnnotated` has no FieldMeta -> skipped.
        self::assertSame(['name', 'count', 'active', 'createdAt', 'colour'], $names);
    }

    public function testStringTypeGetsContainsAndEqOperators(): void
    {
        $descriptors = new ReflectionEntityFieldDescriptor()->describe(StubEntity::class);
        $name = $this->byField($descriptors, 'name');

        self::assertSame('string', $name->type);
        self::assertTrue($name->filterable);
        self::assertSame(['eq', 'ne', 'cn'], $name->filterOperators);
        self::assertTrue($name->sortable);
        self::assertTrue($name->searchable);
    }

    public function testIntTypeGetsComparisonOperators(): void
    {
        $descriptors = new ReflectionEntityFieldDescriptor()->describe(StubEntity::class);
        $count = $this->byField($descriptors, 'count');

        self::assertSame('int', $count->type);
        self::assertSame(['eq', 'ne', 'lt', 'le', 'gt', 'ge'], $count->filterOperators);
    }

    public function testBoolTypeGetsEqualityOnly(): void
    {
        $descriptors = new ReflectionEntityFieldDescriptor()->describe(StubEntity::class);
        $active = $this->byField($descriptors, 'active');

        self::assertSame('bool', $active->type);
        self::assertSame(['eq', 'ne'], $active->filterOperators);
    }

    public function testDateTimeIsClassifiedAsDate(): void
    {
        $descriptors = new ReflectionEntityFieldDescriptor()->describe(StubEntity::class);
        $createdAt = $this->byField($descriptors, 'createdAt');

        self::assertSame('date', $createdAt->type);
        self::assertSame(['eq', 'ne', 'lt', 'le', 'gt', 'ge'], $createdAt->filterOperators);
    }

    public function testEnumFieldEmitsValuesMap(): void
    {
        $descriptors = new ReflectionEntityFieldDescriptor()->describe(StubEntity::class);
        $colour = $this->byField($descriptors, 'colour');

        self::assertSame('enum', $colour->type);
        self::assertSame(['eq', 'ne', 'in', 'ni'], $colour->filterOperators);
        self::assertSame(['red' => 'Red', 'green' => 'Green'], $colour->enumValues);
    }

    public function testExplicitFilterOperatorsWin(): void
    {
        $descriptors = new ReflectionEntityFieldDescriptor()->describe(StubExplicitOpsEntity::class);

        self::assertCount(1, $descriptors);
        self::assertSame(['eq'], $descriptors[0]->filterOperators);
    }

    public function testNonFilterableFieldHasEmptyOperators(): void
    {
        $descriptors = new ReflectionEntityFieldDescriptor()->describe(StubReadOnlyEntity::class);

        self::assertCount(1, $descriptors);
        self::assertFalse($descriptors[0]->filterable);
        self::assertSame([], $descriptors[0]->filterOperators);
        self::assertTrue($descriptors[0]->sortable);
    }

    public function testDescriptorIsCachedPerFqcn(): void
    {
        $descriptor = new ReflectionEntityFieldDescriptor();
        $first = $descriptor->describe(StubEntity::class);
        $second = $descriptor->describe(StubEntity::class);

        self::assertSame($first, $second, 'Same FQCN must return the identical descriptor list (cached).');
    }

    /**
     * @param list<\CoolMS\Entity\Field\FieldDescriptor> $descriptors
     */
    private function byField(array $descriptors, string $field): \CoolMS\Entity\Field\FieldDescriptor
    {
        foreach ($descriptors as $d) {
            if ($d->field === $field) {
                return $d;
            }
        }
        self::fail(sprintf('No descriptor for field "%s"', $field));
    }
}

// --- Fixtures ----------------------------------------------------------------

enum StubColour: string
{
    case Red = 'red';
    case Green = 'green';
}

final class StubEntity
{
    #[FieldMeta(label: 'Name', filterable: true, sortable: true, searchable: true)]
    public string $name = '';

    #[FieldMeta(filterable: true)]
    public int $count = 0;

    #[FieldMeta(filterable: true)]
    public bool $active = false;

    #[FieldMeta(filterable: true)]
    public ?DateTimeImmutable $createdAt = null;

    #[FieldMeta(filterable: true, enumClass: StubColour::class)]
    public ?StubColour $colour = null;

    /** Skipped -- private:true. */
    #[FieldMeta(private: true)]
    public string $internal = '';

    /** Skipped -- no #[FieldMeta]. */
    public string $notAnnotated = '';
}

final class StubExplicitOpsEntity
{
    #[FieldMeta(filterable: true, filterOperators: ['eq'])]
    public string $name = '';
}

final class StubReadOnlyEntity
{
    #[FieldMeta(sortable: true)]
    public string $code = '';
}
