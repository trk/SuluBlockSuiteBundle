<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Twig;

use Totoglu\SuluBlockSuiteBundle\Layout\LayoutResolverInterface;
use Totoglu\SuluBlockSuiteBundle\Layout\PageLayoutProviderInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class LayoutTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly LayoutResolverInterface $layoutResolver,
        private readonly ?PageLayoutProviderInterface $layoutProvider = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('sulu_layout_classes', [$this, 'getClasses']),
            new TwigFunction('layout_classes', [$this, 'getClasses']),
            new TwigFunction('sulu_layout_class', [$this, 'getClass']),
            new TwigFunction('layout_class', [$this, 'getClass']),
            new TwigFunction('sulu_layout_attributes', [$this, 'getAttributes'], ['is_safe' => ['html']]),
            new TwigFunction('layout_attributes', [$this, 'getAttributes'], ['is_safe' => ['html']]),
            new TwigFunction('sulu_layout_load', [$this, 'loadLayout']),
            new TwigFunction('layout_load', [$this, 'loadLayout']),
            new TwigFunction('sulu_layout_render', [$this, 'renderLayout'], ['is_safe' => ['html']]),
            new TwigFunction('layout_render', [$this, 'renderLayout'], ['is_safe' => ['html']]),
            new TwigFunction('sulu_reusable_block_load', [$this, 'loadReusableBlock']),
            new TwigFunction('reusable_block_load', [$this, 'loadReusableBlock']),
            new TwigFunction('sulu_block_suite_wrapper_classes', [$this, 'getWrapperClasses']),
            new TwigFunction('block_suite_wrapper_classes', [$this, 'getWrapperClasses']),
        ];
    }

    /**
     * @param array<string, mixed>|null $props
     */
    public function getWrapperClasses(?array $props, string $extraClass = ''): string
    {
        return $this->layoutResolver->resolveWrapperClasses($props, $extraClass);
    }

    public function getClass(string $group, ?string $value, string $default = ''): string
    {
        return $this->layoutResolver->resolveClass($group, $value, $default);
    }

    /**
     * @param string|array<string, mixed>|null $layout
     * @param array<string, mixed> $context
     */
    public function renderLayout(string|array|null $layout, array $context = [], ?string $locale = null): string
    {
        if (null === $this->layoutProvider) {
            return '';
        }

        return $this->layoutProvider->renderLayout($layout, $context, $locale);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadLayout(string $identifier, ?string $locale = null): ?array
    {
        if (null === $this->layoutProvider) {
            return null;
        }

        return $this->layoutProvider->getLayout($identifier, $locale);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadReusableBlock(string $identifier, ?string $locale = null): ?array
    {
        if (null === $this->layoutProvider) {
            return null;
        }

        return $this->layoutProvider->getReusableBlock($identifier, $locale);
    }

    /**
     * @param array<string, mixed>|null $layoutData
     *
     * @return array<string, string>
     */
    public function getClasses(?array $layoutData): array
    {
        return $this->layoutResolver->resolveClasses($layoutData);
    }

    /**
     * @param array<string, mixed>|null $layoutData
     */
    public function getAttributes(?array $layoutData): string
    {
        $attrs = $this->layoutResolver->resolveAttributes($layoutData);
        $result = [];
        foreach ($attrs as $k => $v) {
            $result[] = \sprintf('%s="%s"', $k, \htmlspecialchars($v, \ENT_QUOTES, 'UTF-8'));
        }

        return \implode(' ', $result);
    }
}
