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
    ) {}

    public function markComponentUsed(string $tagName): void
    {
        $this->usedComponents[$tagName] = true;
    }

    public function addWarning(string $message): void
    {
        $this->warnings[] = $message;
    }
}
