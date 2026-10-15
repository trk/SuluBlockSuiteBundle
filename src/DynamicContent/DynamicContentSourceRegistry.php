<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluArticleSourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluCategorySourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluMediaCollectionSourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluPageSourceProvider;

/**
 * Central registry for all Dynamic Content source providers (both built-in Sulu sources
 * and third-party bundle providers).
 *
 * @phpstan-import-type SourceDescriptor from ConfigurableDynamicContentSourceProviderInterface
 */
final class DynamicContentSourceRegistry
{
    /**
     * @var array<string, DynamicContentSourceProviderInterface>
     */
    private array $providers = [];

    /**
     * @var array<string, array{provider: DynamicContentSourceProviderInterface, multi_item: bool}>
     */
    private array $entriesBySourceKey = [];

    /**
     * @param iterable<DynamicContentSourceProviderInterface> $providers
     */
    public function __construct(
        iterable $providers = [],
        ?PageSourceResolverInterface $pageSourceResolver = null,
    ) {
        $defaultProviders = [
            new SuluPageSourceProvider($pageSourceResolver),
            new SuluArticleSourceProvider(),
            new SuluCategorySourceProvider(),
            new SuluMediaCollectionSourceProvider(),
        ];

        foreach ($defaultProviders as $defaultProvider) {
            $this->registerProvider($defaultProvider);
        }

        foreach ($providers as $provider) {
            $this->registerProvider($provider);
        }
    }

    public function registerProvider(DynamicContentSourceProviderInterface $provider): void
    {
        $this->providers[$provider->getKey()] = $provider;

        if ($provider instanceof ConfigurableDynamicContentSourceProviderInterface) {
            foreach ($provider->getSourceDescriptors('en') as $descriptor) {
                $this->entriesBySourceKey[$descriptor['key']] = [
                    'provider' => $provider,
                    'multi_item' => $descriptor['multi_item'],
                ];
            }

            return;
        }

        $this->entriesBySourceKey[$provider->getKey()] = [
            'provider' => $provider,
            'multi_item' => $provider->isMultiItem(),
        ];
    }

    /**
     * @return array<string, DynamicContentSourceProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->providers;
    }

    /**
     * @return array{provider: DynamicContentSourceProviderInterface, multi_item: bool}|null
     */
    public function getEntry(string $sourceKey): ?array
    {
        return $this->entriesBySourceKey[$sourceKey] ?? null;
    }

    /**
     * Returns all registered source descriptors (single and collection) with their localized labels
     * and declarative admin configuration field schemas.
     *
     * @return list<SourceDescriptor>
     */
    public function getSourceDescriptors(string $locale = 'en'): array
    {
        $lang = \strtolower(\substr($locale, 0, 2));

        /** @var array<string, SourceDescriptor> $byKey */
        $byKey = [
            'none' => [
                'key' => 'none',
                'label' => match ($lang) {
                    'tr' => 'Yok',
                    'de' => 'Keine',
                    'ru' => 'Нет',
                    'es' => 'Ninguno',
                    'fr' => 'Aucun',
                    default => 'None',
                },
                'multi_item' => false,
                'config_fields' => [],
            ],
        ];

        foreach ($this->providers as $provider) {
            if ($provider instanceof ConfigurableDynamicContentSourceProviderInterface) {
                foreach ($provider->getSourceDescriptors($locale) as $descriptor) {
                    $byKey[$descriptor['key']] = $descriptor;
                }
            } else {
                $byKey[$provider->getKey()] = [
                    'key' => $provider->getKey(),
                    'label' => $provider->getLabel($locale),
                    'multi_item' => $provider->isMultiItem(),
                    'config_fields' => $provider->isMultiItem()
                        ? AbstractDynamicContentSourceProvider::defaultCollectionConfigFields()
                        : [],
                ];
            }
        }

        return \array_values($byKey);
    }

    /**
     * Resolves available source fields for field mapping and display conditions.
     *
     * @param array<string, mixed> $context
     *
     * @return list<array{name: string, label: string, type: string}>
     */
    public function getSourceFields(string $sourceKey, string $locale = 'en', array $context = []): array
    {
        $entry = $this->entriesBySourceKey[$sourceKey] ?? ($this->entriesBySourceKey['current_page'] ?? null);
        if (null === $entry) {
            return [];
        }

        $provider = $entry['provider'];
        if ($provider instanceof ConfigurableDynamicContentSourceProviderInterface) {
            return $provider->resolveSourceFields($locale, $context);
        }

        return $provider->getSourceFields($locale);
    }
}
