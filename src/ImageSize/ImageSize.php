<?php

declare(strict_types=1);

namespace AmpConverter\ImageSize;

/**
 * Resolved dimensions and AMP layout strategy for a single <img>/SVG source.
 *
 * `layout` is one of:
 *   - 'responsive' — for regular raster content; needs explicit width/height
 *   - 'intrinsic'  — for SVG with known dimensions and logos that should not
 *                    collapse in flex/inline-block parents
 *   - 'fill'       — fallback for SVG without any size hint; needs sized parent
 *
 * `width`/`height` are null only for `layout: 'fill'`.
 *
 * `warning` carries a human-readable note when a fallback was applied
 * (file missing, getimagesize failed, SVG without dims, etc.). The
 * caller is expected to surface it via Context->warnings.
 */
final readonly class ImageSize
{
    public function __construct(
        public ?int $width,
        public ?int $height,
        public string $layout,
        public ?string $warning = null,
    ) {
    }
}
