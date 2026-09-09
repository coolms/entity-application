<?php

declare(strict_types=1);

namespace CoolMS\EntityApp\Tests\Serializer;

use ArrayAccess;
use CoolMS\Entity\ExtrasProviderInterface;
use CoolMS\Entity\Registry\EntityAliasRegistry;
use CoolMS\Entity\Service\EntitySchemaLookup;
use CoolMS\Entity\Traits\ExtrasProviderTrait;
use CoolMS\EntityApp\Serializer\ExtrasFlatteningNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class ExtrasFlatteningNormalizerTest extends TestCase
{
    // -- normalize ----------------------------------------------------------

    #[Test]
    public function normalizeEmitsApplicableFieldsWithExtrasValues(): void
    {
        $node = new FakeNodeEntity();
        $node->mimeType = 'text/x-dtmpl';
        $node->type = 'file';
        $node['instanceNameSuffix'] = 'v1';

        $lookup = $this->makeSchemaLookup(
            aliasSchema: [],
            instanceSchemas: [
                'mimeType=text/x-dtmpl;type=file' => ['instanceNameSuffix' => []],
            ],
        );
        $normalizer = new ExtrasFlatteningNormalizer(
            $this->makeInner(['id' => 'abc', 'mimeType' => 'text/x-dtmpl', 'type' => 'file', 'extras' => ['instanceNameSuffix' => 'v1']]),
            $lookup,
            new EntityAliasRegistry([]),
        );

        $result = $normalizer->normalize($node);

        self::assertSame('v1', $result['instanceNameSuffix']);
        self::assertArrayNotHasKey('extras', $result);
    }

    #[Test]
    public function normalizeOmitsNonApplicableFieldOnImageNode(): void
    {
        $node = new FakeNodeEntity();
        $node->mimeType = 'image/jpeg';
        $node->type = 'file';
        // Even if extras carries a leftover instanceNameSuffix, it must not surface
        // because the instance-aware schema doesn't include it for image nodes.
        $node['instanceNameSuffix'] = 'stale-value';

        $lookup = $this->makeSchemaLookup(
            aliasSchema: [],
            instanceSchemas: [
                'mimeType=image/jpeg;type=file' => [],
            ],
        );
        $normalizer = new ExtrasFlatteningNormalizer(
            $this->makeInner(['id' => 'abc', 'mimeType' => 'image/jpeg', 'type' => 'file', 'extras' => ['instanceNameSuffix' => 'stale-value']]),
            $lookup,
            new EntityAliasRegistry([]),
        );

        $result = $normalizer->normalize($node);

        self::assertArrayNotHasKey(
            'instanceNameSuffix',
            $result,
            'Non-applicable extras keys must not leak into the output',
        );
        self::assertArrayNotHasKey('extras', $result);
    }

    #[Test]
    public function normalizeEmitsNullWhenApplicableFieldAbsentInExtras(): void
    {
        $node = new FakeNodeEntity();
        $node->mimeType = 'text/x-dtmpl';
        $node->type = 'file';
        // extras does NOT contain instanceNameSuffix

        $lookup = $this->makeSchemaLookup(
            aliasSchema: [],
            instanceSchemas: [
                'mimeType=text/x-dtmpl;type=file' => ['instanceNameSuffix' => []],
            ],
        );
        $normalizer = new ExtrasFlatteningNormalizer(
            $this->makeInner(['id' => 'abc', 'mimeType' => 'text/x-dtmpl', 'type' => 'file', 'extras' => []]),
            $lookup,
            new EntityAliasRegistry([]),
        );

        $result = $normalizer->normalize($node);

        self::assertArrayHasKey('instanceNameSuffix', $result);
        self::assertNull($result['instanceNameSuffix']);
    }

    // -- denormalize --------------------------------------------------------

    #[Test]
    public function denormalizeDropsNonApplicableKeysOnPatch(): void
    {
        $existing = new FakeNodeEntity();
        $existing->mimeType = 'image/jpeg';
        $existing->type = 'file';
        // Pre-existing extras stays untouched.
        $existing['existing'] = 'kept';

        $lookup = $this->makeSchemaLookup(
            aliasSchema: [
                'vfs_node' => ['instanceNameSuffix' => [], 'existing' => []],
            ],
            instanceSchemas: [
                'mimeType=image/jpeg;type=file' => ['existing' => []],
            ],
        );
        $normalizer = new ExtrasFlatteningNormalizer(
            $this->makeInner([]),
            $lookup,
            new EntityAliasRegistry([FakeNodeEntity::class => 'vfs_node']),
        );

        $object = $normalizer->denormalize(
            ['instanceNameSuffix' => 'x'],
            FakeNodeEntity::class,
            null,
            ['object_to_populate' => $existing],
        );

        self::assertInstanceOf(FakeNodeEntity::class, $object);
        self::assertSame('kept', $object['existing'] ?? null);
        self::assertArrayNotHasKey(
            'instanceNameSuffix',
            $object->extras,
            'PATCH on non-applicable instance must not write the field into extras',
        );
    }

    #[Test]
    public function denormalizeWritesApplicableKeysOnPatch(): void
    {
        $existing = new FakeNodeEntity();
        $existing->mimeType = 'text/x-dtmpl';
        $existing->type = 'file';

        $lookup = $this->makeSchemaLookup(
            aliasSchema: [
                'vfs_node' => ['instanceNameSuffix' => []],
            ],
            instanceSchemas: [
                'mimeType=text/x-dtmpl;type=file' => ['instanceNameSuffix' => []],
            ],
        );
        $normalizer = new ExtrasFlatteningNormalizer(
            $this->makeInner([]),
            $lookup,
            new EntityAliasRegistry([FakeNodeEntity::class => 'vfs_node']),
        );

        $object = $normalizer->denormalize(
            ['instanceNameSuffix' => 'v1'],
            FakeNodeEntity::class,
            null,
            ['object_to_populate' => $existing],
        );

        self::assertInstanceOf(FakeNodeEntity::class, $object);
        self::assertSame('v1', $object->extras['instanceNameSuffix']);
    }
    // -- Helpers ------------------------------------------------------------

    /**
     * Build a schema lookup stub. `$instanceSchemas` keys on a property-signature string
     * (`mimeType=text/x-dtmpl;type=file`) so each test instance gets its own filtered schema.
     *
     * @param array<string, array<string, array<string, mixed>>> $aliasSchema     alias -> schema
     * @param array<string, array<string, array<string, mixed>>> $instanceSchemas signature -> schema
     */
    private function makeSchemaLookup(array $aliasSchema, array $instanceSchemas): EntitySchemaLookup
    {
        $lookup = $this->createStub(EntitySchemaLookup::class);
        $lookup->method('getSchemaForEntity')->willReturnCallback(
            static fn (string $alias) => $aliasSchema[$alias] ?? [],
        );
        $lookup->method('getSchemaForInstance')->willReturnCallback(
            static function (object $obj) use ($instanceSchemas): array {
                $sig = sprintf('mimeType=%s;type=%s', $obj->mimeType ?? '', $obj->type ?? '');

                return $instanceSchemas[$sig] ?? [];
            },
        );

        return $lookup;
    }

    /**
     * Inner ObjectNormalizer stub. Returns a fixed array on normalize and a fresh
     * `FakeNodeEntity` on denormalize.
     *
     * @param array<string, mixed> $normalizeResult
     */
    private function makeInner(array $normalizeResult): NormalizerInterface&DenormalizerInterface
    {
        return new class($normalizeResult) implements NormalizerInterface, DenormalizerInterface {
            /** @param array<string, mixed> $result */
            public function __construct(private readonly array $result)
            {
            }

            /** @return array<string, mixed> */
            public function normalize(mixed $data, ?string $format = null, array $context = []): array
            {
                return $this->result;
            }

            public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
            {
                return true;
            }

            public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
            {
                $obj = $context['object_to_populate'] ?? new FakeNodeEntity();
                if (isset($data['mimeType'])) {
                    $obj->mimeType = (string) $data['mimeType'];
                }
                if (isset($data['type'])) {
                    $obj->type = (string) $data['type'];
                }

                return $obj;
            }

            public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
            {
                return true;
            }

            public function getSupportedTypes(?string $format): array
            {
                return ['*' => true];
            }
        };
    }
}

/**
 * @internal test-only ExtrasProvider-shaped stub with public properties so the
 * normalizer can inspect mimeType/type for instance-aware filtering
 *
 * @implements ArrayAccess<string, mixed>
 */
final class FakeNodeEntity implements ExtrasProviderInterface, ArrayAccess
{
    use ExtrasProviderTrait;

    public string $mimeType = '';
    public string $type = '';
}
