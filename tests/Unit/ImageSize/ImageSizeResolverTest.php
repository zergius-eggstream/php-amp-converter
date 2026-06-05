<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit\ImageSize;

use AmpConverter\ImageSize\ImageSize;
use AmpConverter\ImageSize\ImageSizeResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImageSizeResolverTest extends TestCase
{
    private string $siteRoot;

    protected function setUp(): void
    {
        $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'amp-conv-img-' . uniqid('', true);
        @mkdir($tmp . '/public/images', 0777, true);
        $this->siteRoot = $tmp;
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->siteRoot);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function writeRaster(string $relative, int $width, int $height): void
    {
        $path = $this->siteRoot . '/public/' . ltrim($relative, '/');
        @mkdir(dirname($path), 0777, true);
        $im = imagecreatetruecolor($width, $height);
        if ($im === false) {
            self::markTestSkipped('GD imagecreatetruecolor failed');
        }
        imagepng($im, $path);
    }

    private function writeFile(string $relative, string $contents): void
    {
        $path = $this->siteRoot . '/public/' . ltrim($relative, '/');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
    }

    #[Test]
    public function remoteHttpSrcGetsResponsiveFallback(): void
    {
        $result = (new ImageSizeResolver())->resolve('http://example.com/foo.png', $this->siteRoot);
        self::assertSame(800, $result->width);
        self::assertSame(600, $result->height);
        self::assertSame('responsive', $result->layout);
        self::assertNull($result->warning);
    }

    #[Test]
    public function remoteHttpsSrcGetsResponsiveFallback(): void
    {
        $result = (new ImageSizeResolver())->resolve('https://cdn.example.com/x.jpg', $this->siteRoot);
        self::assertSame('responsive', $result->layout);
        self::assertNull($result->warning);
    }

    #[Test]
    public function protocolRelativeSrcGetsResponsiveFallback(): void
    {
        $result = (new ImageSizeResolver())->resolve('//cdn.example.com/x.jpg', $this->siteRoot);
        self::assertSame('responsive', $result->layout);
        self::assertNull($result->warning);
    }

    #[Test]
    public function emptySrcGetsResponsiveFallback(): void
    {
        $result = (new ImageSizeResolver())->resolve('', $this->siteRoot);
        self::assertSame('responsive', $result->layout);
        self::assertNull($result->warning);
    }

    #[Test]
    public function placeholderSrcGetsResponsiveFallbackWithoutTouchingDisk(): void
    {
        // The placeholder comes from SnippetMasker — must not be looked up on disk.
        $result = (new ImageSizeResolver())->resolve('/images/xampphsZ3phsEND.png', $this->siteRoot);
        self::assertSame(800, $result->width);
        self::assertSame(600, $result->height);
        self::assertSame('responsive', $result->layout);
        self::assertNull($result->warning);
    }

    #[Test]
    public function missingFileGetsFallbackWithWarning(): void
    {
        $result = (new ImageSizeResolver())->resolve('/images/missing.png', $this->siteRoot);
        self::assertSame(800, $result->width);
        self::assertSame(600, $result->height);
        self::assertSame('responsive', $result->layout);
        self::assertNotNull($result->warning);
        self::assertStringContainsString('file not found', $result->warning);
    }

    #[Test]
    public function rasterImageReturnsRealDimensionsAsResponsive(): void
    {
        $this->writeRaster('/images/banner.png', 320, 180);
        $result = (new ImageSizeResolver())->resolve('/images/banner.png', $this->siteRoot);
        self::assertSame(320, $result->width);
        self::assertSame(180, $result->height);
        self::assertSame('responsive', $result->layout);
        self::assertNull($result->warning);
    }

    #[Test]
    public function rasterPathWithoutLeadingSlashStillResolves(): void
    {
        $this->writeRaster('/images/logo.png', 64, 64);
        $result = (new ImageSizeResolver())->resolve('images/logo.png', $this->siteRoot);
        self::assertSame(64, $result->width);
        self::assertSame(64, $result->height);
    }

    #[Test]
    public function corruptRasterFallsBackWithWarning(): void
    {
        // Not a valid image — getimagesize() returns false.
        $this->writeFile('/images/broken.png', 'not a real png');
        $result = (new ImageSizeResolver())->resolve('/images/broken.png', $this->siteRoot);
        self::assertSame(800, $result->width);
        self::assertSame(600, $result->height);
        self::assertSame('responsive', $result->layout);
        self::assertNotNull($result->warning);
        self::assertStringContainsString('getimagesize failed', $result->warning);
    }

    #[Test]
    public function svgWithExplicitWidthAndHeightAttrsIsIntrinsic(): void
    {
        $this->writeFile(
            '/images/icon.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="32"><rect width="48" height="32"/></svg>',
        );
        $result = (new ImageSizeResolver())->resolve('/images/icon.svg', $this->siteRoot);
        self::assertSame(48, $result->width);
        self::assertSame(32, $result->height);
        self::assertSame('intrinsic', $result->layout);
        self::assertNull($result->warning);
    }

    #[Test]
    public function svgWithFractionalDimsRoundsToInt(): void
    {
        $this->writeFile(
            '/images/round.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="120.6" height="59.4"></svg>',
        );
        $result = (new ImageSizeResolver())->resolve('/images/round.svg', $this->siteRoot);
        self::assertSame(121, $result->width);
        self::assertSame(59, $result->height);
        self::assertSame('intrinsic', $result->layout);
    }

    #[Test]
    public function svgWithViewBoxOnlyIsIntrinsic(): void
    {
        $this->writeFile(
            '/images/icon.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 100"><path/></svg>',
        );
        $result = (new ImageSizeResolver())->resolve('/images/icon.svg', $this->siteRoot);
        self::assertSame(200, $result->width);
        self::assertSame(100, $result->height);
        self::assertSame('intrinsic', $result->layout);
        self::assertNull($result->warning);
    }

    #[Test]
    public function svgViewBoxAcceptsNegativeOrigin(): void
    {
        $this->writeFile(
            '/images/centered.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="-10 -10 24 24"><circle/></svg>',
        );
        $result = (new ImageSizeResolver())->resolve('/images/centered.svg', $this->siteRoot);
        self::assertSame(24, $result->width);
        self::assertSame(24, $result->height);
        self::assertSame('intrinsic', $result->layout);
    }

    #[Test]
    public function svgWithoutAnyDimsFallsBackToFillWithWarning(): void
    {
        $this->writeFile(
            '/images/blank.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><path/></svg>',
        );
        $result = (new ImageSizeResolver())->resolve('/images/blank.svg', $this->siteRoot);
        self::assertNull($result->width);
        self::assertNull($result->height);
        self::assertSame('fill', $result->layout);
        self::assertNotNull($result->warning);
        self::assertStringContainsString('SVG without dimensions', $result->warning);
    }

    #[Test]
    public function svgPrefersExplicitAttrsOverViewBoxWhenBothPresent(): void
    {
        // Mirrors the JS branch order: (1) width+height attrs wins, even if
        // viewBox would have suggested different dimensions.
        $this->writeFile(
            '/images/dual.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 200 100"></svg>',
        );
        $result = (new ImageSizeResolver())->resolve('/images/dual.svg', $this->siteRoot);
        self::assertSame(48, $result->width);
        self::assertSame(48, $result->height);
        self::assertSame('intrinsic', $result->layout);
    }

    #[Test]
    public function svgExtensionCaseInsensitive(): void
    {
        $this->writeFile(
            '/images/UPPER.SVG',
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="20"></svg>',
        );
        $result = (new ImageSizeResolver())->resolve('/images/UPPER.SVG', $this->siteRoot);
        self::assertSame(10, $result->width);
        self::assertSame(20, $result->height);
        self::assertSame('intrinsic', $result->layout);
    }

    #[Test]
    public function resolveCachesPerInstance(): void
    {
        $this->writeRaster('/images/a.png', 100, 50);
        $resolver = new ImageSizeResolver();
        $first = $resolver->resolve('/images/a.png', $this->siteRoot);

        // Removing the file after first resolve — second call must return
        // the same cached ImageSize, not refetch and find it missing.
        @unlink($this->siteRoot . '/public/images/a.png');

        $second = $resolver->resolve('/images/a.png', $this->siteRoot);
        self::assertSame($first, $second);
        self::assertSame(100, $second->width);
        self::assertSame(50, $second->height);
    }

    #[Test]
    public function cacheIsPerInstanceNotShared(): void
    {
        $this->writeRaster('/images/a.png', 100, 50);
        $first = new ImageSizeResolver();
        $second = new ImageSizeResolver();

        $first->resolve('/images/a.png', $this->siteRoot);
        @unlink($this->siteRoot . '/public/images/a.png');

        // A fresh resolver instance must NOT see the first instance's cache:
        // it will hit the disk, find nothing, return a warning fallback.
        $result = $second->resolve('/images/a.png', $this->siteRoot);
        self::assertNotNull($result->warning);
        self::assertStringContainsString('file not found', $result->warning);
    }
}
