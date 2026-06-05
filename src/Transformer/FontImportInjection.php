<?php

declare(strict_types=1);

namespace AmpConverter\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer;

/**
 * Emits `<link rel="stylesheet" href="...">` tags in `<head>` for the
 * allowlisted font CDN URLs that CssProcessing extracted from
 * `<style amp-custom>` `@import` rules.
 *
 * Runs after CssProcessing (which populates Context->fontImports).
 *
 * Insertion preference, mirroring the JS reference:
 *   1. Insert before `</head>` if present.
 *   2. Otherwise insert immediately after `<meta charset="...">`.
 *   3. Otherwise no-op — the AMP runtime injection stage will deal with
 *      a missing head later.
 *
 * Dedupes URLs preserving first-seen order. Idempotent: a second pass
 * over an already-injected document does nothing because fontImports is
 * tied to Context, which is fresh per conversion.
 */
final class FontImportInjection implements Transformer
{
    public function apply(string $html, Context $ctx): string
    {
        if ($ctx->fontImports === []) {
            return $html;
        }

        $seen = [];
        $links = [];
        foreach ($ctx->fontImports as $url) {
            if (!isset($seen[$url])) {
                $seen[$url] = true;
                $links[] = '<link rel="stylesheet" href="' . $url . '">';
            }
        }
        $block = implode("\n    ", $links);

        if (preg_match('/<\/head>/i', $html) === 1) {
            return (string) preg_replace('/<\/head>/i', "    {$block}\n</head>", $html, 1);
        }

        if (preg_match('/<meta\s+charset=["\']?[^"\'>]+["\']?\s*\/?>/i', $html) === 1) {
            return (string) preg_replace(
                '/(<meta\s+charset=["\']?[^"\'>]+["\']?\s*\/?>)/i',
                "$1\n    {$block}",
                $html,
                1,
            );
        }

        return $html;
    }
}
