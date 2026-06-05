<?php

declare(strict_types=1);

namespace AmpConverter;

/**
 * Mutable per-conversion state shared between transformers in a pipeline.
 *
 * Lives for the duration of a single AmpConverter::convert() call.
 */
final class Context
{
    /** @var array<string, true>  set of AMP custom-element names used in output (e.g. "amp-img", "amp-iframe") */
    public array $usedComponents = [];

    /** @var list<string>  human-readable warnings collected during conversion */
    public array $warnings = [];

    /** @var list<string>  font @import URLs extracted from CSS to be re-emitted as <link rel=stylesheet> */
    public array $fontImports = [];

    /** @var list<string>  PHP/Twig snippets masked before processing, indexed by placeholder number */
    public array $snippetStash = [];

    /** @var list<string>  CSS classes detected as FAQ inner-Q markers (shared between FAQ and CSS-patching transformers) */
    public array $faqQuestionClasses = [];

    public function __construct(
        public readonly string $siteRoot,
        /**
         * Absolute URL of the canonical (non-AMP) version of the page being
         * converted. When provided, AmpRuntimeInjection emits
         * `<link rel="canonical" href="$canonicalUrl">` and replaces any
         * existing canonical link. When null, the injector falls back to a
         * relative self-reference `href="./"`. Lets the host decide how to
         * compute the URL (TSV, per-site config, request) without coupling
         * the package to a specific source.
         */
        public readonly ?string $canonicalUrl = null,
        /**
         * Subdirectory under `$siteRoot` that holds the on-disk assets the
         * converter reads (images for dimension resolution, local CSS files
         * to inline). The default matches the common `public/`-as-document-
         * root convention; hosts with a flat layout pass an empty string,
         * hosts with a different folder pass that folder name.
         */
        public readonly string $assetsBaseDir = 'public',
    ) {}

    /**
     * Absolute path where on-disk assets live for this conversion
     * (= `$siteRoot` joined with `$assetsBaseDir`). Helper so transformers
     * don't repeat the joining logic and so an empty `$assetsBaseDir` just
     * collapses to `$siteRoot`.
     */
    public function assetsRoot(): string
    {
        $root = rtrim($this->siteRoot, '/\\');
        if ($this->assetsBaseDir === '') {
            return $root;
        }

        return $root . DIRECTORY_SEPARATOR . trim($this->assetsBaseDir, '/\\');
    }

    public function markComponentUsed(string $tagName): void
    {
        $this->usedComponents[$tagName] = true;
    }

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }
}
