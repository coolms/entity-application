<?php

declare(strict_types=1);

namespace CoolMS\EntityApp;

use CoolMS\Entity\Registry\FieldExtractorInterface;
use DateTimeInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Stringable;
use Symfony\Component\Uid\Uuid;
use Throwable;

/**
 * Reflection-based default extractor -- works against any entity
 * without per-type wiring. Field discovery rules:
 *
 *  - **Public properties** read directly (PHP 8.5 property hooks
 *    execute through normal access; setters not used).
 *  - **Getter methods** (`getName()`, `isActive()`, `hasFoo()`)
 *    invoked with no args; the resulting property name strips the
 *    prefix and lower-cases the first character (`getDisplayName`
 *    -> `displayName`).
 *  - **`$fields` allow-list** respected when non-null.
 *  - **Uuid / DateTimeInterface** flattened to string forms
 *    (`->toRfc4122()`, `->format(ATOM)`).
 *  - **Stringable values** flattened via `(string) $value`.
 *  - **Nested arrays/scalars** passed through unchanged.
 *  - **Non-scalar/non-stringable objects** skipped -- recursion
 *    risk + serialization risk. Modules that need deeper exposure
 *    ship a custom resolver.
 *
 * Label / secondary heuristics mirror common conventions: `__toString`,
 * `getName`, `getDisplayName`, `getTitle`, `getLabel` for the label;
 * `getEmail`, `getSlug`, `getDescription` for the secondary.
 */
final readonly class ReflectionFieldExtractor implements FieldExtractorInterface
{
    private const array LABEL_GETTERS = ['getDisplayName', 'getName', 'getTitle', 'getLabel'];

    private const array SECONDARY_GETTERS = ['getEmail', 'getSlug', 'getDescription'];

    /**
     * Sentinel: a property whose value can't be safely flattened
     * is dropped rather than serialised to `null` (which would
     * collide with a legitimate null value).
     */
    private const string SKIP = "\x00skip\x00";

    public function extract(object $entity, ?array $fields = null): array
    {
        $rc = new ReflectionClass($entity);
        $allowList = null !== $fields ? array_flip($fields) : null;
        $out = [];

        foreach ($rc->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic()) {
                continue;
            }
            $name = $prop->getName();
            if (null !== $allowList && !isset($allowList[$name])) {
                continue;
            }
            if (!$prop->isInitialized($entity)) {
                continue;
            }
            $value = $prop->getValue($entity);
            $flat = $this->flatten($value);
            if (self::SKIP !== $flat) {
                $out[$name] = $flat;
            }
        }

        foreach ($rc->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
                continue;
            }
            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }
            $name = $this->propertyNameForGetter($method->getName());
            if (null === $name) {
                continue;
            }
            if (array_key_exists($name, $out)) {
                continue;
            }
            if (null !== $allowList && !isset($allowList[$name])) {
                continue;
            }
            try {
                $value = $method->invoke($entity);
            } catch (Throwable) {
                continue;
            }
            $flat = $this->flatten($value);
            if (self::SKIP !== $flat) {
                $out[$name] = $flat;
            }
        }

        return $out;
    }

    public function extractLabel(object $entity): string
    {
        if ($entity instanceof Stringable) {
            $label = (string) $entity;
            if ('' !== $label) {
                return $label;
            }
        }
        foreach (self::LABEL_GETTERS as $getter) {
            $value = $this->tryCall($entity, $getter);
            if (is_string($value) && '' !== $value) {
                return $value;
            }
        }
        // Fallback: class basename + id
        $id = $this->extractId($entity);
        $base = new ReflectionClass($entity)->getShortName();

        return sprintf('%s #%s', $base, (string) $id);
    }

    public function extractSecondary(object $entity): ?string
    {
        foreach (self::SECONDARY_GETTERS as $getter) {
            $value = $this->tryCall($entity, $getter);
            if (is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }

    public function extractId(object $entity): string|int
    {
        $value = $this->tryCall($entity, 'getId');
        if (null === $value) {
            $rc = new ReflectionClass($entity);
            if ($rc->hasProperty('id')) {
                $prop = $rc->getProperty('id');
                if ($prop->isPublic() && $prop->isInitialized($entity)) {
                    $value = $prop->getValue($entity);
                }
            }
        }
        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }
        if (is_int($value) || is_string($value)) {
            return $value;
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }

        throw new RuntimeException(sprintf('Cannot extract id from %s: no `getId()` getter, no public `id` property, or unsupported id type.', $entity::class));
    }

    private function flatten(mixed $value): mixed
    {
        if (null === $value || is_scalar($value)) {
            return $value;
        }
        if ($value instanceof Uuid) {
            return $value->toRfc4122();
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        if ($value instanceof Stringable) {
            return (string) $value;
        }
        if (is_array($value)) {
            return $value;
        }

        // Objects without a defined flattening rule are skipped to
        // avoid serializing entity graphs through reflection.
        return self::SKIP;
    }

    private function propertyNameForGetter(string $methodName): ?string
    {
        foreach (['get', 'is', 'has'] as $prefix) {
            if (str_starts_with($methodName, $prefix) && strlen($methodName) > strlen($prefix)) {
                $remainder = substr($methodName, strlen($prefix));

                return lcfirst($remainder);
            }
        }

        return null;
    }

    private function tryCall(object $entity, string $method): mixed
    {
        if (!method_exists($entity, $method)) {
            return null;
        }
        try {
            return $entity->$method();
        } catch (Throwable) {
            return null;
        }
    }
}
