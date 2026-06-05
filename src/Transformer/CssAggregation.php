<?php

declare(strict_types=1);

namespace AmpConverter\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer;

/**
 * Aggregates external CSS links and inline `<style>` blocks into a single
 * `<style amp-custom>` block — AMP allows only one such block, and AMP
 * only permits `<link rel=stylesheet>` for an allowlisted font CDN
 * (everything else has to be inlined).
 *
 * Port of inlineExternalCss + the <style> consolidation block from
 * tools/convert-rendered-to-amp.js (~lines 105-200 and 1345-1366).
 *
 * Sub-rules (each unit-testable):
 *
 *   - inlineLocalCssLinks: walk every `<link rel="stylesheet">`.
 *     Font-allowlist hrefs are kept verbatim (CssProcessing handles
 *     their font-face imports later). Hrefs that point to a local file
 *     under `<siteRoot>/public/` get their content read and appended
 *     to the aggregation buffer; the `<link>` becomes a tracking
 *     comment. Remote non-font hrefs become a "could not inline"
 *     comment + warning. Duplicate hrefs are deduped (the second
 *     occurrence is dropped).
 *   - consolidateStyleBlocks: every `<style>` without an `amp-*`
 *     attribute gets emptied; its content joins the aggregation
 *     buffer. AMP's own `<style amp-custom>` and `<style amp-boilerplate>`
 *     pass through untouched.
 *   - emitAmpCustom: when the buffer has anything, write it into the
 *     existing `<style amp-custom>` block (if one exists already) or
 *     create one. Anchored before `</head>` when present, else after
 *     `<head>`. If neither, the buffer is dropped with a warning.
 *
 * Runs BEFORE CssProcessing in the pipeline so the rest of the CSS
 * passes (font @import extract, !important strip, …) have a populated
 * `<style amp-custom>` block to operate on.
 */
final class CssAggregation implements Transformer
{
    private const FONT_ALLOWLIST = [
        'fonts.googleapis.com',
        'fonts.gstatic.com',
        'fast.fonts.net',
        'use.typekit.net',
        'use.fontawesome.com',
    ];

    public function apply(string $html, Context $ctx): string
    {
        /** @var list<string> $buffer */
        $buffer = [];

        $html = $this->inlineLocalCssLinks($html, $ctx, $buffer);
        $html = $this->consolidateStyleBlocks($html, $buffer);

        if ($buffer === []) {
            return $html;
        }

        return $this->emitAmpCustom($html, $ctx, implode("\n", $buffer));
    }

    /**
     * @param list<string> $buffer
     */
    private function inlineLocalCssLinks(string $html, Context $ctx, array &$buffer): string
    {
        $seen = [];

        return (string) preg_replace_callback(
            '#<link\b[^>]*\brel=["\']stylesheet["\'][^>]*\/?>#i',
            function (array $m) use ($ctx, &$buffer, &$seen): string {
                if (preg_match('/href=["\']([^"\']+)["\']/', $m[0], $hm) !== 1) {
                    return $m[0];
                }
                $href = $hm[1];
                if ($this->isAllowlistedFont($href)) {
                    return $m[0];
                }
                if (isset($seen[$href])) {
                    return '';
                }
                $seen[$href] = true;

                $css = $this->readLocalCss($href, $ctx->assetsRoot());
                if ($css === null) {
                    $ctx->addWarning('CSS link could not be inlined: ' . $href);

                    return '<!-- CSS link removed (could not inline): ' . $href . ' -->';
                }
                $buffer[] = '/* === ' . $href . ' === */';
                $buffer[] = $css;

                return '<!-- inlined: ' . $href . ' -->';
            },
            $html,
        );
    }

    /**
     * @param list<string> $buffer
     */
    private function consolidateStyleBlocks(string $html, array &$buffer): string
    {
        return (string) preg_replace_callback(
            '#<style\b([^>]*)>([\s\S]*?)</style>#i',
            static function (array $m) use (&$buffer): string {
                if (preg_match('/\bamp-/', $m[1]) === 1) {
                    return $m[0];
                }
                $buffer[] = $m[2];

                return '';
            },
            $html,
        );
    }

    private function emitAmpCustom(string $html, Context $ctx, string $merged): string
    {
        if (preg_match('#<style amp-custom>([\s\S]*?)</style>#i', $html) === 1) {
            return (string) preg_replace_callback(
                '#<style amp-custom>([\s\S]*?)</style>#i',
                static fn (array $m): string => '<style amp-custom>' . $m[1] . "\n" . $merged . '</style>',
                $html,
                1,
            );
        }
        $block = "<style amp-custom>\n" . $merged . "\n</style>";

        if (preg_match('#</head>#i', $html) === 1) {
            return (string) preg_replace(
                '#</head>#i',
                $block . "\n</head>",
                $html,
                1,
            );
        }
        if (preg_match('/<head\b[^>]*>/i', $html) === 1) {
            return (string) preg_replace_callback(
                '/<head\b[^>]*>/i',
                static fn (array $m): string => $m[0] . "\n" . $block,
                $html,
                1,
            );
        }
        $ctx->addWarning('CSS aggregated but no <head> found — block dropped');

        return $html;
    }

    private function isAllowlistedFont(string $href): bool
    {
        foreach (self::FONT_ALLOWLIST as $needle) {
            if (str_contains($href, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function readLocalCss(string $href, string $baseDir): ?string
    {
        $clean = explode('#', explode('?', $href)[0])[0];
        if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://') || str_starts_with($clean, '//')) {
            return null;
        }
        $rel = ltrim($clean, '/');
        $path = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . $rel;
        if (!is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }
}
