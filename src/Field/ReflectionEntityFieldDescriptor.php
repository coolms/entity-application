<?php

declare(strict_types=1);

namespace CoolMS\EntityModule\Field;

use BackedEnum;
use CoolMS\Core\Attribute\FieldMeta;
use CoolMS\Entity\Field\EntityFieldDescriptorInterface;
use CoolMS\Entity\Field\FieldDescriptor;
use DateTimeInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Reflection-based `EntityFieldDescriptorInterface`.
 *
 * Walks the entity class's properties (including those inherited
 * from parents and traits) and emits one `FieldDescriptor` per
 * property carrying a non-`private` `#[FieldMeta]` annotation. The
 * descriptor's operator whitelist is either the explicit
 * `filterOperators` argument or, when null, a sensible default
 * derived from the PHP property type:
 *
 *   string                      → eq, ne, cn (LIKE %x%)
 *   int|float                   → eq, ne, lt, le, gt, ge
 *   bool                        → eq, ne
 *   DateTimeInterface           → eq, ne, lt, le, gt, ge
 *   BackedEnum                  → eq, ne, in, ni
 *   (anything else)             → eq, ne (conservative fallback)
 *
 * Cached per-FQCN — Reflection isn't free and the wizard endpoint
 * is consulted repeatedly during interactive filter editing.
 */
final class ReflectionEntityFieldDescriptor implements EntityFieldDescriptorInterface
{
    /**
     * @var array<class-string, list<FieldDescriptor>>
     */
    private array $cache = [];

    public function describe(string $entityClass): array
    {
        if (isset($this->cache[$entityClass])) {
            return $this->cache[$entityClass];
        }

        $reflection = new ReflectionClass($entityClass);
        $descriptors = [];

        foreach ($reflection->getProperties() as $property) {
            $attrs = $property->getAttributes(FieldMeta::class);
            if ([] === $attrs) {
                continue;
            }
            /** @var FieldMeta $meta */
            $meta = $attrs[0]->newInstance();
            // private:true means "internal — never expose" (persistence
            // bookkeeping columns, blame fields, etc.). Honour the
            // same opt-out the FieldMetaReader pipeline already
            // respects.
            if ($meta->private) {
                continue;
            }

            $type = $this->resolveType($property, $meta->enumClass);

            $descriptors[] = new FieldDescriptor(
                field: $property->getName(),
                label: $meta->label ?? $this->humanize($property->getName()),
                type: $type,
                filterable: $meta->filterable,
                filterOperators: $meta->filterable
                    ? ($meta->filterOperators ?? $this->defaultOperators($type))
                    : [],
                sortable: $meta->sortable,
                searchable: $meta->searchable,
                enumValues: null !== $meta->enumClass
                    ? $this->resolveEnumValues($meta->enumClass)
                    : null,
            );
        }

        return $this->cache[$entityClass] = $descriptors;
    }

    private function resolveType(ReflectionProperty $property, ?string $enumClass): string
    {
        if (null !== $enumClass && is_subclass_of($enumClass, BackedEnum::class)) {
            return 'enum';
        }

        $type = $property->getType();
        if (null === $type) {
            return 'unknown';
        }

        // Union types: pick the first non-null named type as the
        // representative — good enough for wire-type purposes.
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $candidate) {
                if ($candidate instanceof ReflectionNamedType && 'null' !== $candidate->getName()) {
                    return $this->namedTypeToWire($candidate);
                }
            }

            return 'unknown';
        }

        if (!$type instanceof ReflectionNamedType) {
            return 'unknown';
        }

        return $this->namedTypeToWire($type);
    }

    private function namedTypeToWire(ReflectionNamedType $type): string
    {
        $name = $type->getName();

        if ($type->isBuiltin()) {
            return match ($name) {
                'string' => 'string',
                'int' => 'int',
                'float' => 'float',
                'bool' => 'bool',
                default => 'unknown',
            };
        }

        if (is_a($name, BackedEnum::class, true)) {
            return 'enum';
        }
        if (is_a($name, DateTimeInterface::class, true)) {
            return 'date';
        }

        return 'unknown';
    }

    /**
     * @return list<string>
     */
    private function defaultOperators(string $type): array
    {
        return match ($type) {
            'string' => ['eq', 'ne', 'cn'],
            'int',
            'float',
            'date' => ['eq', 'ne', 'lt', 'le', 'gt', 'ge'],
            'bool' => ['eq', 'ne'],
            'enum' => ['eq', 'ne', 'in', 'ni'],
            default => ['eq', 'ne'],
        };
    }

    /**
     * @param class-string $enumClass
     *
     * @return array<string, string>|null
     */
    private function resolveEnumValues(string $enumClass): ?array
    {
        if (!is_subclass_of($enumClass, BackedEnum::class)) {
            return null;
        }
        $out = [];
        foreach ($enumClass::cases() as $case) {
            $value = (string) $case->value;
            $out[$value] = $this->humanize($case->name);
        }

        return $out;
    }

    private function humanize(string $name): string
    {
        $spaced = preg_replace('/(?<!^)([A-Z])/', ' $1', $name) ?? $name;

        return ucfirst(strtolower($spaced[0] ?? '') . substr($spaced, 1));
    }
}
