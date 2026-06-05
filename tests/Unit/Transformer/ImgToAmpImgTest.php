<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer\ImgToAmpImg;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ImgToAmpImgTest extends TestCase
{
    private string $siteRoot;

    protected function setUp(): void
    {
        $this->siteRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'amp-conv-img2-' . uniqid('', true);
        @mkdir($this->siteRoot . '/public/images', 0777, true);
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

    private function writeSvg(string $relative, string $contents): void
    {
        $path = $this->siteRoot . '/public/' . ltrim($relative, '/');
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
    }

    private function ctx(): Context
    {
        return new Context($this->siteRoot);
    }

    /**
     * @return array{html: string, ctx: Context}
     */
    private function apply(string $html, ?Context $ctx = null): array
    {
        $ctx ??= $this->ctx();
        $out = (new ImgToAmpImg())->apply($html, $ctx);

        return ['html' => $out, 'ctx' => $ctx];
    }

    #[Test]
    public function dropsImgWithoutSrcAttribute(): void
    {
        $r = $this->apply('<img class="x">');
        self::assertStringContainsString('<!-- img without src removed', $r['html']);
        self::assertCount(1, $r['ctx']->warnings);
        self::assertStringContainsString('без src', $r['ctx']->warnings[0]);
    }

    #[Test]
    public function dropsImgWithEmptySrc(): void
    {
        $r = $this->apply('<img src="" class="x">');
        self::assertStringContainsString('<!-- img without src removed', $r['html']);
    }

    #[Test]
    public function dropsImgWithMalformedSrcSlashes(): void
    {
        $r = $this->apply('<img src="//" alt="x">');
        self::assertStringContainsString('<!-- img with malformed src removed', $r['html']);
        self::assertStringContainsString('malformed', $r['ctx']->warnings[0]);
    }

    #[Test]
    public function dropsImgWithMalformedSrcSchemeOnly(): void
    {
        $r = $this->apply('<img src="https://" alt="x">');
        self::assertStringContainsString('<!-- img with malformed src removed', $r['html']);
    }

    #[Test]
    public function emitsAmpImgWithResolvedRasterDimensionsAndResponsiveLayout(): void
    {
        $this->writeRaster('/images/banner.png', 320, 180);
        $r = $this->apply('<img src="/images/banner.png" alt="hi">');
        self::assertStringContainsString(
            '<amp-img src="/images/banner.png" alt="hi" width="320" height="180" layout="responsive"></amp-img>',
            $r['html'],
        );
        self::assertSame(['amp-img' => true], $r['ctx']->usedComponents);
    }

    #[Test]
    public function existingNumericWidthAndHeightYieldFixedLayout(): void
    {
        $this->writeRaster('/images/banner.png', 320, 180);
        $r = $this->apply('<img src="/images/banner.png" width="100" height="50">');
        self::assertStringContainsString('width="100" height="50" layout="fixed"', $r['html']);
    }

    #[Test]
    public function nonNumericWidthOrHeightIsIgnoredAndResolvedSizesWin(): void
    {
        $this->writeRaster('/images/b.png', 320, 180);
        $r = $this->apply('<img src="/images/b.png" width="auto" height="100%">');
        self::assertStringContainsString('width="320" height="180" layout="responsive"', $r['html']);
    }

    #[Test]
    public function logoLikeSrcUpgradesResponsiveToIntrinsic(): void
    {
        $this->writeRaster('/images/site-logo.png', 200, 80);
        $r = $this->apply('<img src="/images/site-logo.png" alt="site">');
        self::assertStringContainsString('layout="intrinsic"', $r['html']);
    }

    #[Test]
    public function iconAltUpgradesResponsiveToIntrinsic(): void
    {
        $this->writeRaster('/images/x.png', 64, 64);
        $r = $this->apply('<img src="/images/x.png" alt="user icon">');
        self::assertStringContainsString('layout="intrinsic"', $r['html']);
    }

    #[Test]
    public function badgeClassUpgradesResponsiveToIntrinsic(): void
    {
        $this->writeRaster('/images/x.png', 32, 32);
        $r = $this->apply('<img src="/images/x.png" class="award-badge">');
        self::assertStringContainsString('layout="intrinsic"', $r['html']);
    }

    #[Test]
    public function cyrillicLogotipMatchesLogoLike(): void
    {
        $this->writeRaster('/images/x.png', 100, 40);
        $r = $this->apply('<img src="/images/x.png" alt="логотип">');
        self::assertStringContainsString('layout="intrinsic"', $r['html']);
    }

    #[Test]
    public function avatarLikeParentClassUpgradesToIntrinsic(): void
    {
        // Real-world: sattikrg.kz `<div class="author-photo"><img ...></div>`.
        $this->writeRaster('/images/face.png', 80, 80);
        $r = $this->apply(
            '<div class="author-photo"><img src="/images/face.png" alt="Ivan"></div>',
        );
        self::assertStringContainsString('layout="intrinsic"', $r['html']);
    }

    #[Test]
    public function avatarLikeParentClassCyrillicTriggersIntrinsic(): void
    {
        $this->writeRaster('/images/face.png', 80, 80);
        $r = $this->apply('<div class="user-аватар"><img src="/images/face.png"></div>');
        self::assertStringContainsString('layout="intrinsic"', $r['html']);
    }

    #[Test]
    public function plainContentImageStaysResponsiveWithoutLogoOrAvatarHints(): void
    {
        $this->writeRaster('/images/x.png', 800, 600);
        $r = $this->apply('<p><img src="/images/x.png" alt="some content"></p>');
        self::assertStringContainsString('layout="responsive"', $r['html']);
        self::assertStringNotContainsString('intrinsic', $r['html']);
    }

    #[Test]
    public function stripsLoadingFetchpriorityDecodingAttrs(): void
    {
        $this->writeRaster('/images/x.png', 200, 100);
        $r = $this->apply(
            '<img src="/images/x.png" loading="lazy" fetchpriority="high" decoding="async" alt="x">',
        );
        self::assertStringNotContainsString('loading=', $r['html']);
        self::assertStringNotContainsString('fetchpriority=', $r['html']);
        self::assertStringNotContainsString('decoding=', $r['html']);
        self::assertStringContainsString('alt="x"', $r['html']);
    }

    #[Test]
    public function stripsExistingLayoutAttr(): void
    {
        $this->writeRaster('/images/x.png', 200, 100);
        $r = $this->apply('<img src="/images/x.png" layout="fill" alt="x">');
        self::assertSame(1, substr_count($r['html'], 'layout="'));
        self::assertStringContainsString('layout="responsive"', $r['html']);
    }

    #[Test]
    public function svgWithoutDimensionsBecomesFillNoWidthHeightAttrs(): void
    {
        $this->writeSvg(
            '/images/x.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><path/></svg>',
        );
        $r = $this->apply('<img src="/images/x.svg" alt="x">');
        self::assertStringContainsString('layout="fill"', $r['html']);
        self::assertStringNotContainsString('width=', $r['html']);
        self::assertStringNotContainsString('height=', $r['html']);
        // The SVG fallback warning must surface through Context.
        self::assertNotEmpty($r['ctx']->warnings);
        self::assertStringContainsString('SVG without dimensions', $r['ctx']->warnings[0]);
    }

    #[Test]
    public function svgWithViewBoxIsIntrinsic(): void
    {
        $this->writeSvg(
            '/images/x.svg',
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 50"></svg>',
        );
        $r = $this->apply('<img src="/images/x.svg" alt="x">');
        self::assertStringContainsString('width="100" height="50" layout="intrinsic"', $r['html']);
    }

    #[Test]
    public function missingFileFallsBackTo800x600Responsive(): void
    {
        $r = $this->apply('<img src="/images/missing.png" alt="x">');
        self::assertStringContainsString('width="800" height="600" layout="responsive"', $r['html']);
        // Warning from the resolver gets prefixed with the src.
        self::assertNotEmpty($r['ctx']->warnings);
        self::assertStringContainsString('<img src="/images/missing.png">', $r['ctx']->warnings[0]);
    }

    #[Test]
    public function multipleImgsAreEachProcessed(): void
    {
        $this->writeRaster('/images/a.png', 100, 50);
        $this->writeRaster('/images/b.png', 200, 100);
        $r = $this->apply('<img src="/images/a.png"><br><img src="/images/b.png">');
        self::assertSame(2, substr_count($r['html'], '<amp-img'));
        self::assertStringContainsString('width="100" height="50"', $r['html']);
        self::assertStringContainsString('width="200" height="100"', $r['html']);
    }

    #[Test]
    public function selfClosingImgIsAlsoConverted(): void
    {
        $this->writeRaster('/images/x.png', 64, 32);
        $r = $this->apply('<img src="/images/x.png" alt="x" />');
        self::assertStringContainsString('<amp-img', $r['html']);
        self::assertStringContainsString('width="64" height="32"', $r['html']);
    }

    #[Test]
    public function imageWithoutAltStillConverts(): void
    {
        $this->writeRaster('/images/x.png', 50, 25);
        $r = $this->apply('<img src="/images/x.png">');
        self::assertStringContainsString('<amp-img src="/images/x.png"', $r['html']);
    }
}
