<?php

declare(strict_types=1);

namespace AmpConverter;

/**
 * Entry point. Runs an ordered list of Transformer objects over rendered HTML
 * and returns the converted AMP HTML plus diagnostics.
 *
 *     $result = AmpConverter::createDefault()->convert($renderedHtml, $siteRoot);
 */
final readonly class AmpConverter
{
    /**
     * @param list<Transformer> $transformers
     */
    public function __construct(
        private array $transformers,
    ) {}

    /**
     * Build a converter wired with the default transformer pipeline (matches
     * the Node reference converter behavior).
     */
    public static function createDefault(): self
    {
        return new self(DefaultPipeline::transformers());
    }

    /**
     * @param string      $renderedHtml   the rendered HTML to convert
     * @param string      $siteRoot       absolute path to the site directory used to resolve on-disk assets
     * @param string|null $canonicalUrl   absolute URL of the canonical (non-AMP) version; emitted as <link rel="canonical"> on the AMP page when provided, otherwise the package falls back to a relative self-reference (href="./")
     * @param string      $assetsBaseDir  subdirectory under $siteRoot where assets live (defaults to `public`; pass an empty string for a flat layout, or a custom folder for non-standard layouts)
     */
    public function convert(
        string $renderedHtml,
        string $siteRoot,
        ?string $canonicalUrl = null,
        string $assetsBaseDir = 'public',
    ): ConversionResult {
        $context = new Context(
            siteRoot: $siteRoot,
            canonicalUrl: $canonicalUrl,
            assetsBaseDir: $assetsBaseDir,
        );
        $html = $renderedHtml;

        foreach ($this->transformers as $transformer) {
            $html = $transformer->apply($html, $context);
        }

        return new ConversionResult(
            html: $html,
            usedComponents: array_keys($context->usedComponents),
            warnings: $context->warnings,
        );
    }
}
