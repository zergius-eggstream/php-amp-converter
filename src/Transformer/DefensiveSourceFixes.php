<?php

declare(strict_types=1);

namespace AmpConverter\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer;

/**
 * Defensive cleanup of source HTML quirks that AMP refuses to validate.
 *
 * Bundles all the small regex-driven fixes the JS reference applies inline
 * across tools/convert-rendered-to-amp.js. Each fix is a private method so
 * it can be unit-tested in isolation, and each one is documented inline
 * with the concrete bug it works around when known.
 *
 * Ordering inside apply(): more destructive passes first (script strip),
 * then attribute hygiene, then dedup-like cleanups whose regex captures
 * downstream output of the earlier passes.
 *
 * Excluded from this stage:
 *   - <style amp-custom> internals (Stage 4 CssProcessing).
 *   - HTML skeleton fix-ups, AMP runtime + boilerplate injection, doctype
 *     prepend, noscript wrapping (Stage 12 AmpRuntimeInjection).
 */
final class DefensiveSourceFixes implements Transformer
{
    public function apply(string $html, Context $ctx): string
    {
        $html = $this->stripScriptTags($html);
        $html = $this->stripInlineEventHandlers($html);
        $html = $this->stripAriaRoledescription($html);
        $html = $this->fixUrlSchemeTypos($html);
        $html = $this->upgradeProtocolRelativeUrls($html);
        $html = $this->fixBrokenHeadingClose($html);
        $html = $this->stripDuplicateMetaCharset($html, $ctx);
        $html = $this->stripDuplicateDoctype($html, $ctx);
        $html = $this->stripDuplicateHtmlOpenTag($html, $ctx);
        $html = $this->stripDuplicateHeadAndBody($html, $ctx);
        $html = $this->stripHeadOnlyTagsFromBody($html);
        $html = $this->stripBodyOnlyTagsFromHead($html);
        $html = $this->fixTableNonNumericBorder($html);
        $html = $this->dedupeRelOnAnchors($html);
        $html = $this->dedupeClassOnAnyTag($html);
        $html = $this->stripImgAttrsFromNonMediaTags($html);
        $html = $this->stripPreloadLinks($html);
        $html = $this->stripPreloadAttrOnLink($html);

        return $this->stripOversizedInlineStyle($html, $ctx);
    }

    /**
     * AMP only allows the AMP-runtime <script> (cdn.ampproject.org) and
     * JSON-LD / inline-JSON `<script type="application/(ld+)?json">`
     * blocks for schema.org metadata. Strip everything else — author
     * scripts, async script tags, inline event-firing scripts.
     */
    private function stripScriptTags(string $html): string
    {
        $keep = static function (string $attrs): bool {
            if (str_contains($attrs, 'cdn.ampproject.org')) {
                return true;
            }

            return preg_match('#type=["\']application/(?:ld\+)?json["\']#i', $attrs) === 1;
        };

        $html = (string) preg_replace_callback(
            '#<script\b([^>]*)>[\s\S]*?</script>#i',
            static fn (array $m): string => $keep($m[1]) ? $m[0] : '',
            $html,
        );
        $html = (string) preg_replace_callback(
            '#<script\b([^>]*)\bsrc=[^>]*/>#i',
            static fn (array $m): string => $keep($m[1]) ? $m[0] : '',
            $html,
        );

        return (string) preg_replace_callback(
            '#<script\b([^>]*)\bsrc=["\'][^"\']*["\'][^>]*></script>#i',
            static fn (array $m): string => $keep($m[1]) ? $m[0] : '',
            $html,
        );
    }

    private function stripInlineEventHandlers(string $html): string
    {
        $html = (string) preg_replace('/\s+on[a-z]+="[^"]*"/i', '', $html);

        return (string) preg_replace("/\\s+on[a-z]+='[^']*'/i", '', $html);
    }

    /**
     * `aria-roledescription="..."` is not on the AMP attribute allowlist.
     * Safe to drop unconditionally.
     */
    private function stripAriaRoledescription(string $html): string
    {
        $html = (string) preg_replace('/\s+aria-roledescription=["\'][^"\']*["\']/i', '', $html);

        return (string) preg_replace("/\\s+aria-roledescription='[^']*'/i", '', $html);
    }

    /**
     * Common typo patterns spotted in customer sources:
     *   hts://    htps://    htttps://    httttps://
     * Normalise to `https://` in href/src/action attribute values.
     */
    private function fixUrlSchemeTypos(string $html): string
    {
        $patterns = [
            '#(<[^>]+\b(?:href|src|action)=["\'])hts://#i' => '$1https://',
            '#(<[^>]+\b(?:href|src|action)=["\'])htps://#i' => '$1https://',
            '#(<[^>]+\b(?:href|src|action)=["\'])htttps?://#i' => '$1https://',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $html);
    }

    /**
     * Upgrade protocol-relative URLs `//example.com/x` to https://. AMP
     * accepts both in most contexts, but `<link>` to a font allowlist
     * member needs an explicit scheme, and runtime injection / external
     * CSS inlining downstream both look for `https://` prefixes.
     */
    private function upgradeProtocolRelativeUrls(string $html): string
    {
        return (string) preg_replace(
            '#(<[^>]+\b(?:href|src|action)=["\'])//#i',
            '$1https://',
            $html,
        );
    }

    /**
     * `<h2 text</h2>` — author forgot the closing `>` of the open tag.
     * Replace with the correct `<h2>text</h2>` form.
     */
    private function fixBrokenHeadingClose(string $html): string
    {
        return (string) preg_replace('#<(h[1-6])\s+([^<>]+)</\1>#i', '<$1>$2</$1>', $html);
    }

    /**
     * Only the first `<meta charset>` survives. Subsequent ones get
     * dropped with a warning.
     */
    private function stripDuplicateMetaCharset(string $html, Context $ctx): string
    {
        $seen = false;

        return (string) preg_replace_callback(
            '/<meta\s+charset=[^>]*>/i',
            static function (array $m) use (&$seen, $ctx): string {
                if ($seen) {
                    $ctx->addWarning('duplicate meta charset removed');

                    return '';
                }
                $seen = true;

                return $m[0];
            },
            $html,
        );
    }

    private function stripDuplicateDoctype(string $html, Context $ctx): string
    {
        $seen = false;

        return (string) preg_replace_callback(
            '/<!doctype\b[^>]*>/i',
            static function (array $m) use (&$seen, $ctx): string {
                if ($seen) {
                    $ctx->addWarning('duplicate doctype removed');

                    return '';
                }
                $seen = true;

                return $m[0];
            },
            $html,
        );
    }

    private function stripDuplicateHtmlOpenTag(string $html, Context $ctx): string
    {
        $seen = false;

        return (string) preg_replace_callback(
            '/<html\b[^>]*>/i',
            static function (array $m) use (&$seen, $ctx): string {
                if ($seen) {
                    $ctx->addWarning('nested <html> opening tag removed');

                    return '';
                }
                $seen = true;

                return $m[0];
            },
            $html,
        );
    }

    private function stripDuplicateHeadAndBody(string $html, Context $ctx): string
    {
        foreach (['head', 'body'] as $tag) {
            $seenOpen = false;
            $seenClose = false;
            $html = (string) preg_replace_callback(
                '/<' . $tag . '\b[^>]*>/i',
                static function (array $m) use (&$seenOpen, $tag, $ctx): string {
                    if ($seenOpen) {
                        $ctx->addWarning("duplicate <{$tag}> removed");

                        return '';
                    }
                    $seenOpen = true;

                    return $m[0];
                },
                $html,
            );
            $html = (string) preg_replace_callback(
                '#</' . $tag . '\s*>#i',
                static function (array $m) use (&$seenClose, $tag, $ctx): string {
                    if ($seenClose) {
                        $ctx->addWarning("duplicate </{$tag}> removed");

                        return '';
                    }
                    $seenClose = true;

                    return $m[0];
                },
                $html,
            );
        }

        return $html;
    }

    /**
     * WP plugins sometimes inject body-only tags (admin-bar fragments,
     * theme metadata blobs) inside <head>. Drop spans/divs/paragraphs/
     * headings that AMP would reject in head context.
     */
    private function stripBodyOnlyTagsFromHead(string $html): string
    {
        return (string) preg_replace_callback(
            '#(<head\b[^>]*>)([\s\S]*?)(</head>)#i',
            static function (array $m): string {
                $inner = $m[2];
                $inner = preg_replace('#<span\b[^>]*>[\s\S]*?</span>#i', '', $inner) ?? $inner;
                $inner = preg_replace('#<span\b[^>]*\/?>#i', '', $inner) ?? $inner;
                $inner = preg_replace('#<div\b[^>]*>[\s\S]*?</div>#i', '', $inner) ?? $inner;
                $inner = preg_replace('#<p\b[^>]*>[\s\S]*?</p>#i', '', $inner) ?? $inner;
                $inner = preg_replace('#<h[1-6]\b[^>]*>[\s\S]*?</h[1-6]>#i', '', $inner) ?? $inner;

                return $m[1] . $inner . $m[3];
            },
            $html,
        );
    }

    /**
     * Symmetric: head-only tags that ended up in body (a second <meta>
     * <link> <title> <base> below the fold). Pure noise, drop.
     */
    private function stripHeadOnlyTagsFromBody(string $html): string
    {
        return (string) preg_replace_callback(
            '#(<body\b[^>]*>)([\s\S]*?)(</body>)#i',
            static function (array $m): string {
                $inner = $m[2];
                $inner = preg_replace('#<meta\b[^>]*\/?>#i', '', $inner) ?? $inner;
                $inner = preg_replace('#<link\b[^>]*\/?>#i', '', $inner) ?? $inner;
                $inner = preg_replace('#<title\b[^>]*>[\s\S]*?</title>#i', '', $inner) ?? $inner;
                $inner = preg_replace('#<base\b[^>]*\/?>#i', '', $inner) ?? $inner;

                return $m[1] . $inner . $m[3];
            },
            $html,
        );
    }

    /**
     * AMP wants `border` numeric. Non-numeric values
     * (e.g. `border="3px black"`) get coerced to `"0"`.
     */
    private function fixTableNonNumericBorder(string $html): string
    {
        return (string) preg_replace_callback(
            '/(<table\b[^>]*\s)border=(["\'])([^"\']*)\2/i',
            static function (array $m): string {
                if (preg_match('/^\d+$/', trim($m[3])) === 1) {
                    return $m[0];
                }

                return $m[1] . 'border="0"';
            },
            $html,
        );
    }

    /**
     * `<a rel="nofollow" ... rel="noopener">` — keep all values, but
     * collapse them into a single deduped rel attribute (AMP forbids
     * repeated attributes).
     */
    private function dedupeRelOnAnchors(string $html): string
    {
        return (string) preg_replace_callback(
            '/<a\b([^>]*)>/i',
            static function (array $m): string {
                $attrs = $m[1];
                if (preg_match_all('/\srel=["\']([^"\']*)["\']/i', $attrs, $rels) < 2) {
                    return $m[0];
                }
                $all = [];
                foreach ($rels[1] as $val) {
                    foreach (preg_split('/\s+/', $val) ?: [] as $v) {
                        if ($v !== '') {
                            $all[$v] = true;
                        }
                    }
                }
                $merged = implode(' ', array_keys($all));
                $cleaned = preg_replace('/\srel=["\'][^"\']*["\']/i', '', $attrs) ?? $attrs;

                return '<a' . $cleaned . ' rel="' . $merged . '">';
            },
            $html,
        );
    }

    /**
     * Same idea as dedupeRelOnAnchors but for `class` on any tag. The
     * value-attr regex `(?:[^>"']|"[^"]*"|'[^']*')*` keeps quoted attribute
     * values opaque so we don't false-match inside JSON-LD or inline event
     * fragments.
     */
    private function dedupeClassOnAnyTag(string $html): string
    {
        return (string) preg_replace_callback(
            '/<(\w[\w-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/',
            static function (array $m): string {
                $attrs = $m[2];
                if (preg_match_all('/\sclass=["\']([^"\']*)["\']/i', $attrs, $classes) < 2) {
                    return $m[0];
                }
                $all = [];
                foreach ($classes[1] as $val) {
                    foreach (preg_split('/\s+/', $val) ?: [] as $v) {
                        if ($v !== '') {
                            $all[$v] = true;
                        }
                    }
                }
                $merged = implode(' ', array_keys($all));
                $cleaned = preg_replace('/\sclass=["\'][^"\']*["\']/i', '', $attrs) ?? $attrs;

                return '<' . $m[1] . $cleaned . ' class="' . $merged . '">';
            },
            $html,
        );
    }

    /**
     * `alt`, `loading`, `srcset` are only valid on img/amp-img/area/input/
     * amp-anim. Authors sometimes copy-paste img attributes onto a wrapping
     * div/span — drop those on tags that don't legitimately carry them.
     */
    private function stripImgAttrsFromNonMediaTags(string $html): string
    {
        $allowed = ['img', 'amp-img', 'area', 'input', 'amp-anim'];

        return (string) preg_replace_callback(
            '/<(\w[\w-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/',
            static function (array $m) use ($allowed): string {
                if (in_array(strtolower($m[1]), $allowed, true)) {
                    return $m[0];
                }
                $attrs = $m[2];
                if (preg_match('/\s(?:alt|loading|srcset)=/i', $attrs) !== 1) {
                    return $m[0];
                }
                $cleaned = preg_replace('/\s(?:alt|loading|srcset)=["\'][^"\']*["\']/i', '', $attrs) ?? $attrs;

                return '<' . $m[1] . $cleaned . '>';
            },
            $html,
        );
    }

    /**
     * AMP forbids `<link rel="preload" as="image|font|fetch">` (only the
     * font-trick subset is allowed under specific conditions, which our
     * pipeline doesn't currently emit). Drop them.
     */
    private function stripPreloadLinks(string $html): string
    {
        return (string) preg_replace('#<link[^>]+rel=["\']preload["\'][^>]*\/?>#i', '', $html);
    }

    /**
     * A WP shortcut sometimes adds a bare `preload` attribute to a
     * `<link rel="stylesheet">`. Drop just the attribute, keep the link.
     */
    private function stripPreloadAttrOnLink(string $html): string
    {
        return (string) preg_replace('/(<link\b[^>]*)\bpreload(?=\s|\/?>)/i', '$1', $html);
    }

    /**
     * Inline `style="..."` blobs over 1000 bytes are nearly always copy-
     * paste accidents (Squarespace/Webflow exports). Strip the attr and
     * emit a warning that surfaces the size.
     */
    private function stripOversizedInlineStyle(string $html, Context $ctx): string
    {
        $html = (string) preg_replace_callback(
            '/(<\w+[^>]*?)\sstyle="([^"]{1001,})"([^>]*>)/i',
            static function (array $m) use ($ctx): string {
                $ctx->addWarning('inline style ' . strlen($m[2]) . ' bytes > 1000 stripped');

                return $m[1] . $m[3];
            },
            $html,
        );

        return (string) preg_replace_callback(
            "/(<\\w+[^>]*?)\\sstyle='([^']{1001,})'([^>]*>)/i",
            static function (array $m) use ($ctx): string {
                $ctx->addWarning('inline style ' . strlen($m[2]) . ' bytes > 1000 stripped');

                return $m[1] . $m[3];
            },
            $html,
        );
    }
}
