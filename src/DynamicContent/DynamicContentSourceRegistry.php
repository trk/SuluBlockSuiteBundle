<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\DynamicContent;

use Sulu\Bundle\HttpCacheBundle\ReferenceStore\ReferenceStoreInterface;
use Sulu\Content\Application\ContentAggregator\ContentAggregatorInterface;
use Sulu\Content\Application\ContentResolver\ContentResolverInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Bridge\SuluSmartContentQueryBridge;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SmartContentSourceProviderAdapter;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluArticleSourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluCategorySourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluMediaCollectionSourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluPageSourceProvider;
use Totoglu\SuluBlockSuiteBundle\DynamicContent\Provider\SuluSearchSourceProvider;

/**
 * Central registry for all Dynamic Content source providers (both built-in Sulu sources
 * and third-party bundle providers).
 *
 * @phpstan-import-type SourceDescriptor from ConfigurableDynamicContentSourceProviderInterface
 */
final class DynamicContentSourceRegistry
{
    private const DEDICATED_SMART_CONTENT_TYPES = [
        'pages',
        'articles',
        'articles_page_tree',
        'media',
    ];

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
        ?SuluSmartContentQueryBridge $smartContentBridge = null,
        ?ContentResolverInterface $contentResolver = null,
        ?ContentAggregatorInterface $contentAggregator = null,
        ?RequestStack $requestStack = null,
        ?ReferenceStoreInterface $referenceStore = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {
        foreach ($providers as $provider) {
            $this->registerProvider($provider);
        }

        if (!isset($this->providers['sulu_pages'])) {
            $this->registerProvider(new SuluPageSourceProvider($pageSourceResolver, $this->translator));
        }

        if (!isset($this->providers['sulu_articles'])) {
            $this->registerProvider(new SuluArticleSourceProvider(
                contentResolver: $contentResolver,
                requestStack: $requestStack,
                referenceStore: $referenceStore,
                contentAggregator: $contentAggregator,
                smartContentBridge: $smartContentBridge,
                translator: $this->translator,
            ));
        }

        if (!isset($this->providers['sulu_categories'])) {
            $this->registerProvider(new SuluCategorySourceProvider(
                requestStack: $requestStack,
                referenceStore: $referenceStore,
                translator: $this->translator,
            ));
        }

        if (!isset($this->providers['sulu_media_collection'])) {
            $this->registerProvider(new SuluMediaCollectionSourceProvider(
                requestStack: $requestStack,
                referenceStore: $referenceStore,
                smartContentBridge: $smartContentBridge,
                translator: $this->translator,
            ));
        }

        if (!isset($this->providers['search_results'])) {
            $this->registerProvider(new SuluSearchSourceProvider(
                requestStack: $requestStack,
                translator: $this->translator,
            ));
        }

        if (null !== $smartContentBridge) {
            foreach ($smartContentBridge->getProviders() as $type => $smartProvider) {
                if (\in_array($type, self::DEDICATED_SMART_CONTENT_TYPES, true)) {
                    continue;
                }

                $adapter = new SmartContentSourceProviderAdapter(
                    smartContentProvider: $smartProvider,
                    smartContentBridge: $smartContentBridge,
                    contentResolver: $contentResolver,
                    contentAggregator: $contentAggregator,
                    requestStack: $requestStack,
                    referenceStore: $referenceStore,
                    translator: $this->translator,
                );

                if (!isset($this->providers[$adapter->getKey()])) {
                    $this->registerProvider($adapter);
                }
            }
        }
    }

    public function getTranslator(): ?TranslatorInterface
    {
        return $this->translator;
    }

    public function registerProvider(DynamicContentSourceProviderInterface $provider): void
    {
        if (
            $provider instanceof AbstractDynamicContentSourceProvider
            && null === $provider->getTranslator()
            && null !== $this->translator
        ) {
            $provider->setTranslator($this->translator);
        }

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
        /** @var array<string, SourceDescriptor> $byKey */
        $byKey = [
            'none' => [
                'key' => 'none',
                'label' => AbstractDynamicContentSourceProvider::translateKey(
                    'sulu_block_suite.dynamic_source_none',
                    $locale,
                    'None',
                    $this->translator,
                ),
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
