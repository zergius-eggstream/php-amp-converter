<?php

declare(strict_types=1);

namespace ZergiusEggstream\AmpConverter\PhpSnippets;

use ZergiusEggstream\AmpConverter\Context;
use ZergiusEggstream\AmpConverter\Transformer;

/**
 * Pipeline-stage final: restore stashed Twig/PHP snippets from placeholders.
 *
 * Reads Context::$snippetStash populated by MaskSnippets.
 */
final class UnmaskSnippets implements Transformer
{
    public function apply(string $html, Context $context): string
    {
        return SnippetMasker::unmask($html, $context->snippetStash);
    }
}
