<?php

declare(strict_types=1);

namespace CoolMS\Entity\Application;

use CoolMS\Entity\Registry\RepositoryRegistryInterface;
use CoolMS\Rql\RqlRepositoryInterface;
use OutOfBoundsException;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/**
 * DI-tagged implementation. Modules register their searchable
 * repositories via:
 *
 * ```
 * $container->getDefinition(UserRepository::class)
 *     ->addTag('coolms.entity_repository', ['entityType' => User::class]);
 * ```
 *
 * The `AutowireLocator` keys the resulting `ServiceLocator` by the
 * tag's `entityType` attribute (per Symfony's `index` / `default`
 * method conventions for tagged locators).
 */
final readonly class TaggedRepositoryRegistry implements RepositoryRegistryInterface
{
    /**
     * @param ServiceLocator<RqlRepositoryInterface> $locator
     */
    public function __construct(
        #[AutowireLocator('coolms.entity_repository', indexAttribute: 'entityType')]
        private ServiceLocator $locator,
    ) {
    }

    public function has(string $entityType): bool
    {
        return $this->locator->has($entityType);
    }

    public function get(string $entityType): RqlRepositoryInterface
    {
        if (!$this->locator->has($entityType)) {
            throw new OutOfBoundsException(sprintf('No repository registered for entity type "%s". Tag the repository with `coolms.entity_repository` and `entityType: %s::class`.', $entityType, $entityType));
        }

        // The locator's PHPDoc generic pins the return type -- Symfony
        // would have thrown at compile time if a tagged service that
        // doesn't implement RqlRepositoryInterface had been registered
        // under this tag.
        return $this->locator->get($entityType);
    }
}
