<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer\IframeConversion;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IframeConversionTest extends TestCase
{
    /**
     * @return array{html: string, ctx: Context}
     */
    private function apply(string $html): array
    {
        $ctx = new Context('/tmp/site');
        $out = (new IframeConversion())->apply($html, $ctx);

        return ['html' => $out, 'ctx' => $ctx];
    }

    // === YouTube ===

    #[Test]
    public function youtubeEmbedBecomesAmpYoutube(): void
    {
        $r = $this->apply('<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" frameborder="0"></iframe>');
        self::assertStringContainsString('<amp-youtube data-videoid="dQw4w9WgXcQ"', $r['html']);
        self::assertStringContainsString('width="480" height="270" layout="responsive"', $r['html']);
        self::assertSame(['amp-youtube' => true], $r['ctx']->usedComponents);
    }

    #[Test]
    public function youtuBeShortLinkBecomesAmpYoutube(): void
    {
        $r = $this->apply('<iframe src="https://youtu.be/abcDEF12345?t=10"></iframe>');
        self::assertStringContainsString('data-videoid="abcDEF12345"', $r['html']);
    }

    #[Test]
    public function youtubeWithQueryParamsExtractsOnlyId(): void
    {
        $r = $this->apply(
            '<iframe src="https://www.youtube.com/embed/foo123?rel=0&autoplay=1"></iframe>',
        );
        self::assertStringContainsString('data-videoid="foo123"', $r['html']);
    }

    // === Other iframe layout decisions ===

    #[Test]
    public function bothNumericDimsBecomeResponsiveWithThoseSizes(): void
    {
        $r = $this->apply('<iframe src="https://example.com/x" width="800" height="450"></iframe>');
        self::assertStringContainsString('width="800" height="450" layout="responsive"', $r['html']);
        self::assertSame(['amp-iframe' => true], $r['ctx']->usedComponents);
    }

    #[Test]
    public function percentWidthWithNumericHeightBecomesFixedHeight(): void
    {
        // Real-world: sattikrg.kz iframe width="100%" height="500".
        $r = $this->apply('<iframe src="https://demo.example.com/" width="100%" height="500"></iframe>');
        self::assertStringContainsString('width="auto" height="500" layout="fixed-height"', $r['html']);
    }

    #[Test]
    public function autoWidthWithNumericHeightBecomesFixedHeight(): void
    {
        $r = $this->apply('<iframe src="https://demo.example.com/" width="auto" height="320"></iframe>');
        self::assertStringContainsString('width="auto" height="320" layout="fixed-height"', $r['html']);
    }

    #[Test]
    public function missingWidthWithNumericHeightBecomesFixedHeight(): void
    {
        $r = $this->apply('<iframe src="https://demo.example.com/" height="320"></iframe>');
        self::assertStringContainsString('width="auto" height="320" layout="fixed-height"', $r['html']);
    }

    #[Test]
    public function bothPercentBecomesFillWithoutDimAttrs(): void
    {
        // melada.kz case.
        $r = $this->apply('<iframe src="https://aviator.example/" width="100%" height="100%"></iframe>');
        self::assertStringContainsString('layout="fill"', $r['html']);
        self::assertStringNotContainsString('width=', $r['html']);
        self::assertStringNotContainsString('height=', $r['html']);
    }

    #[Test]
    public function noDimensionsAtAllBecomes480x270Responsive(): void
    {
        $r = $this->apply('<iframe src="https://example.com/x"></iframe>');
        self::assertStringContainsString('width="480" height="270" layout="responsive"', $r['html']);
    }

    // === Skip / drop cases ===

    #[Test]
    public function httpIframeIsDroppedWithWarning(): void
    {
        $r = $this->apply('<iframe src="http://insecure.example/x" width="100" height="100"></iframe>');
        self::assertStringContainsString('<!-- iframe skipped', $r['html']);
        self::assertNotEmpty($r['ctx']->warnings);
        self::assertSame([], $r['ctx']->usedComponents);
    }

    #[Test]
    public function placeholderSrcIsDroppedWithWarning(): void
    {
        $r = $this->apply('<iframe src="xampphsZ3phsEND" width="100" height="100"></iframe>');
        self::assertStringContainsString('<!-- iframe skipped', $r['html']);
        self::assertNotEmpty($r['ctx']->warnings);
    }

    #[Test]
    public function missingSrcIsDroppedWithWarning(): void
    {
        $r = $this->apply('<iframe width="100" height="100"></iframe>');
        self::assertStringContainsString('<!-- iframe skipped', $r['html']);
    }

    // === Sandbox + extras ===

    #[Test]
    public function ampIframeAlwaysGetsSandboxAndFrameborder(): void
    {
        $r = $this->apply('<iframe src="https://example.com/x" width="100" height="100"></iframe>');
        self::assertStringContainsString('sandbox="allow-scripts allow-same-origin allow-popups allow-forms"', $r['html']);
        self::assertStringContainsString('frameborder="0"', $r['html']);
    }

    #[Test]
    public function allowfullscreenIsPreservedWhenPresent(): void
    {
        $r = $this->apply('<iframe src="https://example.com/x" width="100" height="100" allowfullscreen></iframe>');
        self::assertStringContainsString(' allowfullscreen></amp-iframe>', $r['html']);
    }

    #[Test]
    public function titleAttrIsPreservedWhenPresent(): void
    {
        $r = $this->apply('<iframe src="https://example.com/x" width="100" height="100" title="Demo"></iframe>');
        self::assertStringContainsString('title="Demo"', $r['html']);
    }

    #[Test]
    public function bareAmpersandInSrcGetsEscapedToAmpEntity(): void
    {
        $r = $this->apply('<iframe src="https://example.com/x?a=1&b=2" width="100" height="100"></iframe>');
        self::assertStringContainsString('src="https://example.com/x?a=1&amp;b=2"', $r['html']);
    }

    #[Test]
    public function alreadyEncodedAmpEntityIsNotDoubleEscaped(): void
    {
        $r = $this->apply('<iframe src="https://example.com/x?a=1&amp;b=2" width="100" height="100"></iframe>');
        self::assertStringContainsString('src="https://example.com/x?a=1&amp;b=2"', $r['html']);
        self::assertStringNotContainsString('&amp;amp;', $r['html']);
    }

    // === Self-closing variant ===

    #[Test]
    public function selfClosingIframeIsAlsoConverted(): void
    {
        $r = $this->apply('<iframe src="https://example.com/x" width="100" height="100" />');
        self::assertStringContainsString('<amp-iframe', $r['html']);
    }

    // === Canvas ===

    #[Test]
    public function canvasWithContentBecomesComment(): void
    {
        $r = $this->apply('<canvas id="x"><span>fallback</span></canvas>');
        self::assertStringContainsString('<!-- canvas removed', $r['html']);
    }

    #[Test]
    public function emptyCanvasBecomesComment(): void
    {
        $r = $this->apply('<canvas id="x"></canvas>');
        self::assertStringContainsString('<!-- canvas removed', $r['html']);
    }

    #[Test]
    public function selfClosingCanvasBecomesComment(): void
    {
        $r = $this->apply('<canvas/>');
        self::assertStringContainsString('<!-- canvas removed', $r['html']);
    }

    // === Multi-occurrence ===

    #[Test]
    public function multipleIframesAreEachConverted(): void
    {
        $r = $this->apply(
            '<iframe src="https://www.youtube.com/embed/foo"></iframe>'
            . '<iframe src="https://demo.example/" width="100%" height="500"></iframe>',
        );
        self::assertStringContainsString('amp-youtube', $r['html']);
        self::assertStringContainsString('amp-iframe', $r['html']);
        self::assertSame(['amp-youtube' => true, 'amp-iframe' => true], $r['ctx']->usedComponents);
    }
}
