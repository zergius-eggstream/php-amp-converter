<?php

declare(strict_types=1);

namespace AmpConverter\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer;

/**
 * Port of the CSS cleanup pass inside `<style amp-custom>` plus inline
 * `style="..."` !important strip, from tools/convert-rendered-to-amp.js
 * (~line 1369 and ~line 1216).
 *
 * Each rule from the spec table is a private method so it can be unit-tested
 * in isolation against a synthetic CSS fragment.
 *
 * Stages 10 (FAQ) and 11 (auto-contrast) own additional CSS rewriting hooks
 * that also live inside the same `<style amp-custom>` block in the JS source
 * (resolveAutoContrastVars, patchAccordionCss, patchFaqCssSpecificity,
 * injectQuestionClassDefaults, stripScrollRevealHidden). They are NOT part
 * of this transformer — they will be separate transformers slotted into the
 * pipeline by the stages that own them.
 */
final class CssProcessing implements Transformer
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
        $html = $this->processAmpCustomBlock($html, $ctx);

        return $this->stripImportantInInlineStyle($html);
    }

    private function processAmpCustomBlock(string $html, Context $ctx): string
    {
        return (string) preg_replace_callback(
            '/<style amp-custom>([\s\S]*?)<\/style>/i',
            function (array $m) use ($ctx): string {
                $css = $m[1];
                $css = $this->decodeHtmlEntitiesInCss($css);
                $this->extractFontImports($css, $ctx);
                $css = $this->stripImportant($css);
                $css = $this->stripImports($css);
                $css = $this->stripCharset($css);
                $css = $this->neutraliseVendorMediaFeatures($css);
                $css = $this->stripBrokenCustomProperties($css);

                return "<style amp-custom>{$css}</style>";
            },
            $html,
        );
    }

    /**
     * Source HTML often HTML-escapes characters inside CSS (template engines
     * doing context-naive htmlspecialchars). Decode the handful that AMP
     * validator would otherwise flag.
     */
    private function decodeHtmlEntitiesInCss(string $css): string
    {
        return strtr($css, [
            '&#039;' => "'",
            '&#39;' => "'",
            '&quot;' => '"',
            '&amp;' => '&',
            '&lt;' => '<',
            '&gt;' => '>',
        ]);
    }

    /**
     * @import shrifty pre-strip step: pull out the font-CDN URLs so a later
     * transformer can re-emit them as <link rel="stylesheet"> in <head>
     * (AMP forbids @import inside <style amp-custom> but allows <link> to
     * an allowlisted font CDN). Non-font @imports (Bootstrap, Swiper, etc.)
     * are NOT collected — they get stripped in stripImports().
     */
    private function extractFontImports(string $css, Context $ctx): void
    {
        $pattern = '/@import\s+(?:url\(\s*["\']?([^"\')]+)["\']?\s*\)|["\']([^"\']+)["\'])[^;]*;/i';
        if (preg_match_all($pattern, $css, $matches) === false) {
            return;
        }
        $count = count($matches[0]);
        for ($i = 0; $i < $count; $i++) {
            $url = trim($matches[1][$i] !== '' ? $matches[1][$i] : $matches[2][$i]);
            if ($url !== '' && $this->isAllowlistedFont($url)) {
                $ctx->fontImports[] = $url;
            }
        }
    }

    private function isAllowlistedFont(string $url): bool
    {
        foreach (self::FONT_ALLOWLIST as $needle) {
            if (str_contains($url, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function stripImportant(string $css): string
    {
        return (string) preg_replace('/\s*!important/i', '', $css);
    }

    /**
     * Note: the @import URL may itself contain `;` inside `url(...)` —
     * e.g. Google Fonts `family=PT+Sans:wght@400;700`. The non-greedy
     * URL alternative handles that without prematurely terminating on `;`.
     */
    private function stripImports(string $css): string
    {
        return (string) preg_replace(
            '/@import\s+(?:url\([^)]*\)|"[^"]*"|\'[^\']*\')[^;]*;/i',
            '',
            $css,
        );
    }

    private function stripCharset(string $css): string
    {
        return (string) preg_replace('/@charset\s+["\'][^"\']+["\']\s*;?/i', '', $css);
    }

    /**
     * Vendor-prefixed media features (`-moz-touch-enabled`,
     * `-webkit-min-device-pixel-ratio`, ...) make the AMP validator reject
     * the stylesheet. Replace each `(-vendor-feature: value)` token with
     * a benign always-true `(min-width: 0)` so the surrounding @media block
     * stays structurally valid.
     */
    private function neutraliseVendorMediaFeatures(string $css): string
    {
        return (string) preg_replace('/\(-[a-z]+-[\w-]+:\s*[^)]+\)/i', '(min-width: 0)', $css);
    }

    /**
     * A custom property `--var: value with 'unbalanced quote;` is broken
     * CSS and confuses the AMP validator. Drop any --var declaration whose
     * value has an odd number of single OR double quotes.
     */
    private function stripBrokenCustomProperties(string $css): string
    {
        return (string) preg_replace_callback(
            '/--[\w-]+\s*:[^;}]*;/',
            static function (array $m): string {
                $decl = $m[0];
                $single = substr_count($decl, "'");
                $double = substr_count($decl, '"');
                if ($single % 2 !== 0 || $double % 2 !== 0) {
                    return '/* broken custom property removed */';
                }

                return $decl;
            },
            $css,
        );
    }

    /**
     * `!important` is rejected by AMP not just inside <style amp-custom>
     * but also inside element `style="..."` attributes. Strip from both.
     */
    private function stripImportantInInlineStyle(string $html): string
    {
        return (string) preg_replace_callback(
            '/(\bstyle=)(["\'])([^"\']*)\2/i',
            static function (array $m): string {
                if (!preg_match('/!important/i', $m[3])) {
                    return $m[0];
                }
                $stripped = preg_replace('/\s*!important/i', '', $m[3]) ?? $m[3];

                return $m[1] . $m[2] . $stripped . $m[2];
            },
            $html,
        );
    }
}
