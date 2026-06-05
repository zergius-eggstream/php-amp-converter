<?php

declare(strict_types=1);

namespace AmpConverter\ImageSize;

/**
 * Resolves dimensions + AMP layout for an <img src="..."> by reading the file
 * from disk under a caller-provided `$baseDir`.
 *
 * Port of `resolveImageSize` in tools/convert-rendered-to-amp.js (~line 58).
 * Per-instance cache, no global state.
 *
 * The caller chooses where assets live by composing `$baseDir` themselves —
 * typically `Context::assetsRoot()` (= siteRoot + assetsBaseDir). The
 * resolver makes no assumption about a `public/` subdirectory: hosts with
 * flat layouts, custom folders, or no on-disk assets at all just pass the
 * appropriate path.
 *
 * Behaviour (no exceptions thrown — all errors become warnings + sensible
 * fallback ImageSize, per the strict-but-graceful policy):
 *
 *   - Empty / http(s) / protocol-relative src → 800×600 responsive (no warning).
 *   - Placeholder src (after SnippetMasker) → 800×600 responsive (no warning).
 *   - File not found under $baseDir → 800×600 responsive + warning.
 *   - .svg: explicit width/height attrs → intrinsic; else viewBox → intrinsic;
 *           else fill + warning.
 *   - raster: getimagesize() success → responsive; failure → 800×600
 *             responsive + warning.
 */
final class ImageSizeResolver
{
    private const FALLBACK_WIDTH = 800;
    private const FALLBACK_HEIGHT = 600;

    /** @var array<string, ImageSize> */
    private array $cache = [];

    public function resolve(string $src, string $baseDir): ImageSize
    {
        if ($src === '' || str_starts_with($src, 'http') || str_starts_with($src, '//')) {
            return new ImageSize(self::FALLBACK_WIDTH, self::FALLBACK_HEIGHT, 'responsive');
        }
        if (str_contains($src, 'xampphsZ')) {
            return new ImageSize(self::FALLBACK_WIDTH, self::FALLBACK_HEIGHT, 'responsive');
        }
        if (isset($this->cache[$src])) {
            return $this->cache[$src];
        }

        $relative = str_starts_with($src, '/') ? substr($src, 1) : $src;
        $imgPath = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . $relative;

        if (!is_file($imgPath)) {
            $result = new ImageSize(
                self::FALLBACK_WIDTH,
                self::FALLBACK_HEIGHT,
                'responsive',
                "file not found: {$imgPath}",
            );
        } elseif (str_ends_with(strtolower($src), '.svg')) {
            $result = $this->resolveSvg($imgPath);
        } else {
            $result = $this->resolveRaster($imgPath);
        }

        return $this->cache[$src] = $result;
    }

    private function resolveSvg(string $path): ImageSize
    {
        $svg = @file_get_contents($path);
        if ($svg === false) {
            return new ImageSize(null, null, 'fill', "SVG read failed: {$path}");
        }

        // (1) Explicit width + height on <svg>.
        if (
            preg_match('/<svg\b[^>]*\bwidth=["\']([\d.]+)["\']/i', $svg, $w) === 1
            && preg_match('/<svg\b[^>]*\bheight=["\']([\d.]+)["\']/i', $svg, $h) === 1
        ) {
            return new ImageSize(
                (int) round((float) $w[1]),
                (int) round((float) $h[1]),
                'intrinsic',
            );
        }

        // (2) viewBox="minX minY W H".
        if (preg_match('/viewBox=["\']\s*[-\d.]+\s+[-\d.]+\s+([\d.]+)\s+([\d.]+)\s*["\']/i', $svg, $vb) === 1) {
            return new ImageSize(
                (int) round((float) $vb[1]),
                (int) round((float) $vb[2]),
                'intrinsic',
            );
        }

        // (3) No size hint at all.
        return new ImageSize(null, null, 'fill', 'SVG without dimensions, using fill');
    }

    private function resolveRaster(string $path): ImageSize
    {
        $dim = @getimagesize($path);
        if ($dim === false) {
            return new ImageSize(
                self::FALLBACK_WIDTH,
                self::FALLBACK_HEIGHT,
                'responsive',
                "getimagesize failed: {$path}",
            );
        }

        return new ImageSize($dim[0], $dim[1], 'responsive');
    }
}
