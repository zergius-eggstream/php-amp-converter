<?php

declare(strict_types=1);

namespace AmpConverter\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer;

/**
 * Final assembly pass: rewrites the head skeleton into a valid AMP one
 * and injects the runtime + boilerplate + custom-element scripts for
 * every component the earlier transformers marked as used.
 *
 * Port of the head/skeleton bits from
 * tools/convert-rendered-to-amp.js (~lines 1160-1175, 1336-1342, 1683-
 * 1727), bundled together because they all manipulate the same head
 * structure and order matters.
 *
 * Sub-rules (each unit-testable):
 *
 *   - selfCloseOrphanLinks: a source-bug where `<link href="..."` is
 *     followed by whitespace and another open tag without its own `>`
 *     — close it with `/>` to keep the head parseable.
 *   - convertHttpEquivToMetaCharset: AMP wants `<meta charset>`, not
 *     the http-equiv content-type form. Rewrite.
 *   - addAmpAttributeToHtml: `<html lang="…">` becomes `<html ⚡ lang>`
 *     (idempotent: skip if already marked).
 *   - stripNonAmpNoscript: AMP allows <noscript> only when it wraps
 *     `<style amp-boilerplate>`. Anything else gets dropped.
 *   - injectRuntimeAndBoilerplate: append the runtime script tag plus
 *     `customElementScript()` for each used component plus the
 *     boilerplate block. Anchored after <meta charset> when present,
 *     else after the head open tag. Idempotent: skip when v0.js is
 *     already there. usedComponents are emitted in sorted order so
 *     output is deterministic regardless of detection order earlier
 *     in the pipeline.
 *   - injectCanonical: if no <link rel=canonical> is present, insert
 *     a self-reference (`href="./"`) right before </head>. This is
 *     a no-op for hosts that already emit a canonical link.
 */
final class AmpRuntimeInjection implements Transformer
{
    private const RUNTIME = '<script async src="https://cdn.ampproject.org/v0.js"></script>';

    private const BOILERPLATE = '<style amp-boilerplate>body{-webkit-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-moz-animation:-amp-start 8s steps(1,end) 0s 1 normal both;-ms-animation:-amp-start 8s steps(1,end) 0s 1 normal both;animation:-amp-start 8s steps(1,end) 0s 1 normal both}@-webkit-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-moz-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-ms-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@-o-keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}@keyframes -amp-start{from{visibility:hidden}to{visibility:visible}}</style><noscript><style amp-boilerplate>body{-webkit-animation:none;-moz-animation:none;-ms-animation:none;animation:none}</style></noscript>';

    public function apply(string $html, Context $ctx): string
    {
        $html = $this->selfCloseOrphanLinks($html);
        $html = $this->convertHttpEquivToMetaCharset($html);
        $html = $this->addAmpAttributeToHtml($html);
        $html = $this->stripNonAmpNoscript($html);
        $html = $this->injectRuntimeAndBoilerplate($html, $ctx);

        return $this->injectCanonical($html, $ctx);
    }

    private function selfCloseOrphanLinks(string $html): string
    {
        return (string) preg_replace(
            '#(<link\b[^>]*\bhref=["\'][^"\']*["\'])\s*(?=<(?:link|meta|script|style|head|body)\b)#i',
            "$1/>\n        ",
            $html,
        );
    }

    private function convertHttpEquivToMetaCharset(string $html): string
    {
        return (string) preg_replace(
            '#<meta\s+http-equiv=["\']Content-Type["\']\s+content=["\']text/html;\s*charset=[^"\']+["\']\s*/?>#i',
            '<meta charset="utf-8">',
            $html,
        );
    }

    private function addAmpAttributeToHtml(string $html): string
    {
        return (string) preg_replace_callback(
            '/<html\b([^>]*)>/i',
            static function (array $m): string {
                // Idempotent: skip if already marked.
                if (str_contains($m[1], '⚡') || preg_match('/\bamp\b/', $m[1]) === 1) {
                    return $m[0];
                }

                return '<html ⚡' . $m[1] . '>';
            },
            $html,
            1,
        );
    }

    private function stripNonAmpNoscript(string $html): string
    {
        return (string) preg_replace_callback(
            '#<noscript>([\s\S]*?)</noscript>#i',
            static function (array $m): string {
                return preg_match('/<style\s+amp-boilerplate>/i', $m[1]) === 1 ? $m[0] : '';
            },
            $html,
        );
    }

    private function injectRuntimeAndBoilerplate(string $html, Context $ctx): string
    {
        // Idempotency check.
        if (str_contains($html, 'cdn.ampproject.org/v0.js')) {
            return $html;
        }

        $components = array_keys($ctx->usedComponents);
        // amp-img is built into v0.js and must NOT get a custom-element
        // script — emitting one is a validator error.
        $components = array_values(array_filter(
            $components,
            static fn (string $c): bool => $c !== 'amp-img',
        ));
        sort($components);

        $injection = "\n    " . self::RUNTIME;
        foreach ($components as $name) {
            $injection .= "\n    " . $this->customElementScript($name);
        }
        $injection .= "\n    " . self::BOILERPLATE;

        if (preg_match('/<meta\s+charset=["\']?[^"\'>]+["\']?\s*\/?>/i', $html) === 1) {
            return (string) preg_replace(
                '/(<meta\s+charset=["\']?[^"\'>]+["\']?\s*\/?>)/i',
                '$1' . $injection,
                $html,
                1,
            );
        }
        if (preg_match('/<head\b[^>]*>/i', $html) === 1) {
            return (string) preg_replace_callback(
                '/<head\b[^>]*>/i',
                static fn (array $m): string => $m[0] . $injection,
                $html,
                1,
            );
        }
        $ctx->addWarning('no <head> or <meta charset> found — AMP runtime NOT injected');

        return $html;
    }

    private function customElementScript(string $name): string
    {
        return '<script async custom-element="' . $name . '" src="https://cdn.ampproject.org/v0/' . $name . '-0.1.js"></script>';
    }

    /**
     * When `$ctx->canonicalUrl` is provided, ALWAYS emit
     * `<link rel="canonical" href="$canonicalUrl">`, replacing any pre-existing
     * canonical link the source HTML may have carried. This is the host's
     * declarative intent — "use this URL", and we respect it.
     *
     * When `$ctx->canonicalUrl` is null, fall back to the current behaviour:
     * keep any existing canonical link as-is, and only inject a relative
     * self-reference `href="./"` when there's no canonical at all. This keeps
     * the package usable as a drop-in for hosts that don't want / need to
     * compute absolute URLs at build time.
     */
    private function injectCanonical(string $html, Context $ctx): string
    {
        $href = $ctx->canonicalUrl ?? './';

        $existing = preg_match('/<link\s+[^>]*\brel=["\']canonical["\'][^>]*>/i', $html) === 1;

        if ($ctx->canonicalUrl !== null && $existing) {
            // Replace existing canonical with the caller-supplied URL.
            return (string) preg_replace(
                '/<link\s+[^>]*\brel=["\']canonical["\'][^>]*>/i',
                '<link rel="canonical" href="' . $href . '">',
                $html,
                1,
            );
        }

        if ($existing) {
            return $html;
        }

        if (preg_match('#</head>#i', $html) !== 1) {
            return $html;
        }

        return (string) preg_replace(
            '#</head>#i',
            '    <link rel="canonical" href="' . $href . "\">\n</head>",
            $html,
            1,
        );
    }
}
