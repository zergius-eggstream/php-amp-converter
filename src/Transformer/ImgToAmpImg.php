<?php

declare(strict_types=1);

namespace AmpConverter\Transformer;

use AmpConverter\Context;
use AmpConverter\ImageSize\ImageSizeResolver;
use AmpConverter\Transformer;

/**
 * Port of the <img> → <amp-img> rewrite from tools/convert-rendered-to-amp.js
 * (~line 1440), spec rule 2.x.
 *
 * Each sub-rule is a private method so it can be unit-tested in isolation:
 *
 *   - shouldDropForMissingSrc()
 *   - shouldDropForMalformedSrc()
 *   - extractNumericDim()
 *   - cleanIncomingAttrs()
 *   - pickLayout()  — covers the LOGO_LIKE / AVATAR_LIKE upgrade to intrinsic
 *   - parentClassFromContext()
 *
 * Behaviour follows the strict-but-graceful policy: bad inputs become HTML
 * comments with warnings on the Context, never thrown exceptions.
 */
final class ImgToAmpImg implements Transformer
{
    /** Regex for src/alt/class hints that the image is a logo/icon (Latin + Cyrillic). */
    private const LOGO_LIKE_RE = '/logo|icon|badge|логотип/iu';

    /**
     * Regex for parent-element class names that wrap sized avatars/photos.
     * Authors tend to use one of these names on the container so their CSS
     * `.X img { width:Npx }` works — without intrinsic, the inner <img>
     * collapses to 0×0 in flex/inline-block parents.
     */
    private const AVATAR_LIKE_RE = '/photo|avatar|portrait|profile|author|reviewer|аватар|фото/iu';

    /** How many characters of preceding HTML to scan for the parent class. */
    private const PARENT_CONTEXT_WINDOW = 300;

    public function __construct(
        private readonly ImageSizeResolver $sizes = new ImageSizeResolver(),
    ) {
    }

    public function apply(string $html, Context $ctx): string
    {
        $count = 0;
        $result = preg_replace_callback(
            '/<img\b([^>]*?)\/?>/i',
            function (array $m) use ($html, $ctx): string {
                // PREG_OFFSET_CAPTURE → each match group is [string, offset].
                $attrs = $m[1][0];
                $offset = $m[0][1];

                return $this->rewriteImg($attrs, $offset, $html, $ctx);
            },
            $html,
            -1,
            $count,
            PREG_OFFSET_CAPTURE,
        );

        return $result ?? $html;
    }

    private function rewriteImg(string $attrs, int $offset, string $html, Context $ctx): string
    {
        $src = $this->extractSrc($attrs);
        if ($src === null) {
            $ctx->addWarning('<img> без src (lightbox placeholder?) — удалён');

            return '<!-- img without src removed (was JS-filled placeholder) -->';
        }
        if ($this->isMalformedSrc($src)) {
            $ctx->addWarning('<img> с malformed src="' . $src . '" — удалён');

            return '<!-- img with malformed src removed -->';
        }

        $existingW = $this->extractNumericDim($attrs, 'width');
        $existingH = $this->extractNumericDim($attrs, 'height');
        $resolved = $this->sizes->resolve($src, $ctx->siteRoot);
        if ($resolved->warning !== null) {
            $ctx->addWarning('<img src="' . $src . '">: ' . $resolved->warning);
        }

        $cleanAttrs = $this->cleanIncomingAttrs($attrs);
        $width = $existingW ?? $resolved->width;
        $height = $existingH ?? $resolved->height;
        $layout = $this->pickLayout(
            $attrs,
            $this->precedingContext($html, $offset),
            $existingW,
            $existingH,
            $resolved->layout,
        );

        $ctx->markComponentUsed('amp-img');

        $dimAttrs = $layout === 'fill' || $width === null || $height === null
            ? ''
            : ' width="' . $width . '" height="' . $height . '"';

        return '<amp-img ' . $cleanAttrs . $dimAttrs . ' layout="' . $layout . '"></amp-img>';
    }

    private function precedingContext(string $html, int $offset): string
    {
        if ($offset <= 0) {
            return '';
        }
        $start = max(0, $offset - self::PARENT_CONTEXT_WINDOW);

        return substr($html, $start, $offset - $start);
    }

    /**
     * Empty `src=""` is matched ([^"\']*) so we can still report the warning
     * — distinguishing it from a totally absent src attribute would not
     * change downstream behaviour (both drop the tag).
     */
    private function extractSrc(string $attrs): ?string
    {
        if (preg_match('/src=["\']([^"\']*)["\']/', $attrs, $m) !== 1) {
            return null;
        }
        if ($m[1] === '') {
            return null;
        }

        return $m[1];
    }

    private function isMalformedSrc(string $src): bool
    {
        $trim = trim($src);

        return $trim === '//' || preg_match('#^https?://?$#i', $trim) === 1;
    }

    /**
     * Extract a numeric value for attr name. AMP requires integer dimensions —
     * "auto" or "100%" must be dropped so the resolver-provided fallback wins.
     */
    private function extractNumericDim(string $attrs, string $name): ?int
    {
        if (preg_match('/\s' . $name . '=["\']([^"\']+)["\']/i', $attrs, $m) !== 1) {
            return null;
        }
        $raw = trim($m[1]);
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }

        return (int) $raw;
    }

    /**
     * Strip attrs we own (width/height/layout) or that AMP forbids on amp-img
     * (loading, fetchpriority, decoding). Returns the remaining attrs trimmed
     * (no leading or trailing whitespace).
     */
    private function cleanIncomingAttrs(string $attrs): string
    {
        $cleaned = preg_replace(
            '/\s+(?:loading|fetchpriority|decoding|width|height|layout)=["\'][^"\']*["\']/i',
            '',
            $attrs,
        );

        return trim($cleaned ?? $attrs);
    }

    /**
     * Layout pick order, mirroring the JS:
     *   1. Both width AND height numeric in the source → fixed
     *      (logos/icons that should not stretch).
     *   2. Resolver's layout (responsive / fill / intrinsic).
     *   3. If resolver returned responsive AND the tag self-identifies as
     *      a logo (LOGO_LIKE in attrs) OR the parent element's class hints
     *      avatar/photo wrapping → upgrade to intrinsic so the author CSS
     *      `width:Npx;height:Npx` survives.
     */
    private function pickLayout(
        string $attrs,
        string $contextBefore,
        ?int $existingW,
        ?int $existingH,
        string $resolvedLayout,
    ): string {
        if ($existingW !== null && $existingH !== null) {
            return 'fixed';
        }
        if ($resolvedLayout !== 'responsive') {
            return $resolvedLayout;
        }
        if (preg_match(self::LOGO_LIKE_RE, $attrs) === 1) {
            return 'intrinsic';
        }
        $parentClass = $this->parentClassFromContext($contextBefore);
        if ($parentClass !== null && preg_match(self::AVATAR_LIKE_RE, $parentClass) === 1) {
            return 'intrinsic';
        }

        return 'responsive';
    }

    /**
     * Look at the last opening tag right before the <img> position and pull
     * its `class="..."` value if any. Returns null when no class attribute
     * is found in the trailing parent tag.
     */
    private function parentClassFromContext(string $context): ?string
    {
        if ($context === '') {
            return null;
        }
        if (preg_match('/<[a-z][\w-]*[^>]*\bclass=["\']([^"\']+)["\'][^>]*>\s*$/i', $context, $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
