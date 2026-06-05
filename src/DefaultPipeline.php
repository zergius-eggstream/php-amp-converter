<?php

declare(strict_types=1);

namespace AmpConverter;

use AmpConverter\PhpSnippets\MaskSnippets;
use AmpConverter\PhpSnippets\UnmaskSnippets;
use AmpConverter\Transformer\CssProcessing;
use AmpConverter\Transformer\FontImportInjection;
use AmpConverter\Transformer\ImgToAmpImg;

/**
 * Factory of the default transformer pipeline. Order matters: each transformer
 * may depend on side effects of the previous one (e.g. SnippetMasker must run
 * first and last to wrap/unwrap PHP/Twig snippets around the rest of the work).
 *
 * Empty for now — transformers are added incrementally as the port progresses
 * (see doc/port-status.md).
 */
final class DefaultPipeline
{
    /**
     * @return list<Transformer>
     */
    public static function transformers(): array
    {
        return [
            new MaskSnippets(),
            // TODO Stage 3:  Transformer/HtmlSkeleton
            // TODO Stage 3:  Transformer/ScriptStripping
            new CssProcessing(),
            new ImgToAmpImg(),
            // TODO Stage 6:  Transformer/IframeConversion
            // TODO Stage 7:  Transformer/FormConversion
            // TODO Stage 8:  Transformer/DefensiveSourceFixes
            // TODO Stage 9:  Transformer/BurgerToAmpBind
            // TODO Stage 10: Transformer/FaqToAccordion
            // TODO Stage 11: Transformer/AutoContrastVars
            // TODO Stage 12: Transformer/PurgeCss
            new FontImportInjection(),
            // TODO Stage 12: Transformer/AmpRuntimeInjection
            new UnmaskSnippets(),
        ];
    }
}
