<?php

declare(strict_types=1);

namespace AmpConverter\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer;

/**
 * Port of the iframe / YouTube / canvas rewrites from
 * tools/convert-rendered-to-amp.js (~lines 1508-1571 plus 1520-1522).
 *
 * Order matters within a single apply():
 *   1. YouTube iframes → <amp-youtube> (most specific).
 *   2. <canvas> → comment (AMP needs amp-script; we don't translate that).
 *   3. Other iframes → <amp-iframe>, picking layout from numeric/percent
 *      dimensions (or fall back to responsive 16:9).
 *
 * Per-instance, no shared state. Marks amp-youtube / amp-iframe as used
 * components in Context only when a tag was emitted.
 */
final class IframeConversion implements Transformer
{
    public function apply(string $html, Context $ctx): string
    {
        $html = $this->convertYoutubeIframes($html, $ctx);
        $html = $this->stripCanvas($html);

        return $this->convertOtherIframes($html, $ctx);
    }

    private function convertYoutubeIframes(string $html, Context $ctx): string
    {
        $pattern = '#<iframe\b[^>]*\bsrc=["\']([^"\']*(?:youtube\.com/embed/|youtu\.be/)[^"\']+)["\'][^>]*>[\s\S]*?</iframe>#i';

        return (string) preg_replace_callback(
            $pattern,
            function (array $m) use ($ctx): string {
                if (preg_match('#(?:youtube\.com/embed/|youtu\.be/)([^?&"\']+)#', $m[1], $id) !== 1) {
                    return $m[0];
                }
                $ctx->markComponentUsed('amp-youtube');

                return '<amp-youtube data-videoid="' . $id[1] . '" width="480" height="270" layout="responsive"></amp-youtube>';
            },
            $html,
        );
    }

    private function stripCanvas(string $html): string
    {
        $html = (string) preg_replace(
            '#<canvas\b[^>]*>[\s\S]*?</canvas>#i',
            '<!-- canvas removed (needs amp-script) -->',
            $html,
        );

        return (string) preg_replace(
            '#<canvas\b[^>]*\/?>#i',
            '<!-- canvas removed -->',
            $html,
        );
    }

    private function convertOtherIframes(string $html, Context $ctx): string
    {
        $callback = function (array $m) use ($ctx): string {
            return $this->rewriteOneIframe($m[0], $ctx);
        };
        $html = (string) preg_replace_callback(
            '#<iframe\b[^>]*>[\s\S]*?</iframe>#i',
            $callback,
            $html,
        );

        return (string) preg_replace_callback(
            '#<iframe\b[^>]*\/?>#i',
            $callback,
            $html,
        );
    }

    private function rewriteOneIframe(string $tag, Context $ctx): string
    {
        if (preg_match('#^<iframe\b([^>]*?)\/?>#i', $tag, $am) !== 1) {
            return $tag;
        }
        $attrs = $am[1];

        $src = $this->extractAttr($attrs, 'src');
        $src = $src !== null ? trim($src) : '';

        if (
            $src === ''
            || preg_match('#^https://#i', $src) !== 1
            || str_contains($src, 'xampphsZ')
        ) {
            $ctx->addWarning('iframe не сконвертирован (нужен static https-src): ' . substr($src, 0, 60));

            return '<!-- iframe skipped (amp-iframe requires static https src) -->';
        }

        $safeSrc = $this->escapeAmpInSrc($src);
        [$layout, $dimAttrs] = $this->pickIframeLayout($attrs);
        $title = $this->extractTitleAttr($attrs);
        $allowFs = preg_match('/\ballowfullscreen\b/i', $attrs) === 1 ? ' allowfullscreen' : '';

        $ctx->markComponentUsed('amp-iframe');

        return '<amp-iframe src="' . $safeSrc . '"' . $dimAttrs
            . ' layout="' . $layout . '"'
            . ' sandbox="allow-scripts allow-same-origin allow-popups allow-forms"'
            . ' frameborder="0"' . $allowFs . $title . '></amp-iframe>';
    }

    /**
     * @return array{0: string, 1: string}  [layout, dimAttrs]
     */
    private function pickIframeLayout(string $attrs): array
    {
        $wRaw = $this->extractAttr($attrs, 'width');
        $hRaw = $this->extractAttr($attrs, 'height');
        $wNum = $wRaw !== null && preg_match('/^\d+$/', trim($wRaw)) === 1 ? trim($wRaw) : null;
        $hNum = $hRaw !== null && preg_match('/^\d+$/', trim($hRaw)) === 1 ? trim($hRaw) : null;
        $wIsPctOrAuto = $wRaw !== null && preg_match('/%|auto/i', $wRaw) === 1;
        $hIsPctOrAuto = $hRaw !== null && preg_match('/%|auto/i', $hRaw) === 1;

        if ($wNum !== null && $hNum !== null) {
            return ['responsive', ' width="' . $wNum . '" height="' . $hNum . '"'];
        }
        // Mixed: numeric height + percentage/auto/missing width → fixed-height
        // (gaming-iframe pattern width=100% height=500; sattikrg.kz fix 2026-06-04).
        // Must NOT be fill: without sized parent, fill expands to viewport.
        if ($hNum !== null && ($wIsPctOrAuto || $wRaw === null)) {
            return ['fixed-height', ' width="auto" height="' . $hNum . '"'];
        }
        if ($wIsPctOrAuto && $hIsPctOrAuto) {
            return ['fill', ''];
        }

        return ['responsive', ' width="480" height="270"'];
    }

    private function extractAttr(string $attrs, string $name): ?string
    {
        if (preg_match('/\b' . $name . '=["\']([^"\']*)["\']/i', $attrs, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private function extractTitleAttr(string $attrs): string
    {
        if (preg_match('/\btitle=["\'][^"\']*["\']/i', $attrs, $m) !== 1) {
            return '';
        }

        return ' ' . trim($m[0]);
    }

    /**
     * Bare `&` inside URL parameters becomes `&amp;` so the AMP validator
     * doesn't reject the whole tag. Don't double-escape already-encoded
     * entities (&amp;/&lt;/&gt;/&quot;/&#NN/&#xHH).
     */
    private function escapeAmpInSrc(string $src): string
    {
        return (string) preg_replace(
            '/&(?!(?:amp|lt|gt|quot|#\d+|#x[0-9a-fA-F]+);)/',
            '&amp;',
            $src,
        );
    }
}
