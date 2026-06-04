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

    public function convert(string $renderedHtml, string $siteRoot): ConversionResult
    {
        $context = new Context(siteRoot: $siteRoot);
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
