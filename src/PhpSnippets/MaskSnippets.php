<?php

declare(strict_types=1);

namespace ZergiusEggstream\AmpConverter\PhpSnippets;

use ZergiusEggstream\AmpConverter\Context;
use ZergiusEggstream\AmpConverter\Transformer;

/**
 * Pipeline-stage 1: replace dynamic snippets with placeholders so later
 * regex-based transformers don't accidentally tear them apart.
 *
 * Stashes originals in Context::$snippetStash for UnmaskSnippets to restore.
 */
final class MaskSnippets implements Transformer
{
    public function apply(string $html, Context $context): string
    {
        $result = SnippetMasker::mask($html);
        $context->snippetStash = $result['stash'];

        return $result['cleaned'];
    }
}
