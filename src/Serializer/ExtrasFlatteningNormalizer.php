<?php

declare(strict_types=1);

namespace CoolMS\EntityApp\Serializer;

use CoolMS\Entity\Contract\ExtrasNormalizationExclusionInterface;
use CoolMS\Entity\ExtrasProviderInterface;
use CoolMS\Entity\Registry\EntityAliasRegistry;
use CoolMS\Entity\Service\EntitySchemaLookup;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Flattens an ExtrasProviderInterface entity's `$extras` bag onto the normalized
 * top-level array. Routes write-side data into `$extras` via __set on denormalize.
 *
 * Dispatches on aliased ExtrasProvider entities (e.g. PageVariant, IdentityProfile).
 * Types that another module normalizes itself opt out through
 * {@see ExtrasNormalizationExclusionInterface}. Such a module normally also
 * registers its own normalizer at a higher priority, so it wins for those
 * types even before the exclusion is consulted.
 */
readonly class ExtrasFlatteningNormalizer implements NormalizerInterface, DenormalizerInterface
{
    /**
     * SIGNATURE LOCKED. Service is registered with explicit arguments and
     * `setAutowired(false)` in two coordinated places:
     *   - CoolMS\EntityBundle\DependencyInjection\Extension::registerServices()
     *   - CoolMS\EntityBundle\DependencyInjection\Compiler\ExtrasInfrastructurePass::process()
     *     re-asserts `$objectNormalizer` (bound to the framework
     *     `serializer.normalizer.object` ID, which is not autowire-resolvable
     *     by interface intersection) and `$aliasRegistry` after services.yaml
     *     auto-scan
     * Adding a new constructor param requires updating both sites in
     * lock-step.
     *
     * @param iterable<ExtrasNormalizationExclusionInterface> $exclusions
     */
    public function __construct(
        private NormalizerInterface&DenormalizerInterface $objectNormalizer,
        private EntitySchemaLookup $schemaLookup,
        private EntityAliasRegistry $aliasRegistry,
        private iterable $exclusions = [],
    ) {
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        // Prevent infinite recursion: delegate with a sentinel context key
        $innerContext = $context + ['dynamic_normalized' => true];

        // Normalize scalar / system fields first (id, createdAt, ...)
        /** @var array<string, mixed> $normalizedData */
        $normalizedData = $this->objectNormalizer->normalize($data, $format, $innerContext);

        if ($data instanceof ExtrasProviderInterface) {
            // Instance-aware schema: only fields whose `appliesTo` matches this entity
            // appear in the output. Each schema-defined field is emitted with its extras
            // value, or null when not set. Extras keys outside the resolved schema are
            // filtered out so non-applicable fields (e.g., template-only fields on an
            // image node) never leak.
            $instanceSchema = $this->schemaLookup->getSchemaForInstance($data);
            $extras = $data->extras;
            foreach ($instanceSchema as $fieldName => $_cfg) {
                if (array_key_exists($fieldName, $normalizedData)) {
                    continue; // static class property wins
                }
                $normalizedData[$fieldName] = $extras[$fieldName] ?? null;
            }
        }

        // Remove the raw extras bag -- dynamic values are now at the top level.
        // This is equivalent to #[Ignore] on $extras but avoids the ORM mapping conflicts
        // that a PHP property-override in the subclass would introduce.
        unset($normalizedData['extras']);

        return $normalizedData;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        // First pass: split static class-declared fields from dynamic (extras) fields
        // using the broad alias schema. This routes static keys (e.g., mimeType, type)
        // to ObjectNormalizer so they land on the entity before instance-aware filtering.
        $alias = $this->aliasRegistry->getAlias($type);
        $aliasSchemaKeys = array_keys($this->schemaLookup->getSchemaForEntity($alias));
        $aliasFlip = array_flip($aliasSchemaKeys);
        $staticData = array_diff_key($data, $aliasFlip);
        $dynamicData = array_intersect_key($data, $aliasFlip);

        // Prevent infinite recursion when delegating to ObjectNormalizer
        $innerContext = $context + ['dynamic_normalized' => true];

        // Denormalize static fields through the standard path. For PATCH, $object_to_populate
        // carries existing mimeType/type; for POST, the inner call applies them from $staticData.
        $object = $this->objectNormalizer->denormalize($staticData, $type, $format, $innerContext);

        // Second pass: now that the entity has its discriminating properties set,
        // re-filter dynamic data through the instance-aware schema and write only
        // keys that pass `appliesTo`. Non-applicable writes are silently dropped --
        // sending an image node `instanceNameSuffix` via PATCH is a no-op on extras.
        if (is_object($object)) {
            $instanceSchemaKeys = array_keys($this->schemaLookup->getSchemaForInstance($object));
            $allowed = array_intersect_key($dynamicData, array_flip($instanceSchemaKeys));
            foreach ($allowed as $key => $value) {
                $object->$key = $value;
            }
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if (isset($context['dynamic_normalized'])) {
            return false;
        }

        // Types another module normalizes itself (e.g. pure-runtime DynamicRecord
        // entities) reach here only when their higher-priority normalizer is absent.
        if (is_object($data) && $this->isExcluded($data)) {
            return false;
        }

        // Only intercept actual domain entities that are registered in the alias map.
        // API resource DTOs that implement ExtrasProviderInterface must NOT be
        // intercepted here -- they rely on the API layer's own item normalizer so
        // that JSON-LD metadata (@context, @id, @type) is generated correctly.
        // Their dynamic field values are exposed via __get, and dynamic field
        // names reach the schema through the property metadata factories.
        return is_object($data) && $this->aliasRegistry->hasAlias($data::class);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        if (isset($context['dynamic_normalized'])) {
            return false;
        }

        // When the operation has an explicit input class that differs from $type,
        // the API layer's own item normalizer must handle the redirect first.
        // Intercepting here would build a $type instance instead of the intended
        // input class, making dynamic-field lookups fail with null.
        $inputClass = $context['input']['class'] ?? null;
        if (null !== $inputClass && $inputClass !== $type) {
            return false;
        }

        // Claimed types take their own module's normalizer path (higher priority).
        if ($this->isExcluded($type)) {
            return false;
        }

        // Extension-mode entities that are explicitly registered in the alias registry
        // (e.g. IdentityProfile, PageVariant).
        return $this->aliasRegistry->hasAlias($type);
    }

    public function getSupportedTypes(?string $format): array
    {
        return [ExtrasProviderInterface::class => true];
    }

    private function isExcluded(object|string $objectOrClass): bool
    {
        foreach ($this->exclusions as $exclusion) {
            if ($exclusion->excludes($objectOrClass)) {
                return true;
            }
        }

        return false;
    }
}
