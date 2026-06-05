<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer\CssProcessing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CssProcessingTest extends TestCase
{
    private function apply(string $html, ?Context $ctx = null): string
    {
        return (new CssProcessing())->apply($html, $ctx ?? new Context('/tmp/site'));
    }

    private function wrap(string $css): string
    {
        return "<style amp-custom>{$css}</style>";
    }

    #[Test]
    public function decodesAmpersandEntitiesInsideCss(): void
    {
        $html = $this->wrap("a::before { content: &#039;hi&#039;; } .b { font-family: &quot;Foo&quot;; }");
        $out = $this->apply($html);
        self::assertStringContainsString("content: 'hi';", $out);
        self::assertStringContainsString('font-family: "Foo";', $out);
        self::assertStringNotContainsString('&#039;', $out);
        self::assertStringNotContainsString('&quot;', $out);
    }

    #[Test]
    public function decodesAmp39LtGtEntities(): void
    {
        $html = $this->wrap('a { content: &#39;x&#39;; b: &lt;ok&gt;; c: a &amp; b; }');
        $out = $this->apply($html);
        self::assertStringContainsString("content: 'x';", $out);
        self::assertStringContainsString('b: <ok>;', $out);
        self::assertStringContainsString('c: a & b;', $out);
    }

    #[Test]
    public function stripsImportantFromAmpCustom(): void
    {
        $html = $this->wrap('.x { color: red !important; }');
        self::assertSame($this->wrap('.x { color: red; }'), $this->apply($html));
    }

    #[Test]
    public function stripsCharset(): void
    {
        $html = $this->wrap('@charset "UTF-8"; .x { color: red; }');
        $out = $this->apply($html);
        self::assertStringNotContainsString('@charset', $out);
        self::assertStringContainsString('.x { color: red; }', $out);
    }

    #[Test]
    public function stripsNonFontImports(): void
    {
        $html = $this->wrap('@import url("https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"); .x { color: red; }');
        $out = $this->apply($html);
        self::assertStringNotContainsString('@import', $out);
        self::assertStringContainsString('.x { color: red; }', $out);
    }

    #[Test]
    public function stripsImportAlsoFromAmpCustomEvenWhenFontAllowlisted(): void
    {
        // Font @imports get *captured* in fontImports AND also stripped from
        // the CSS — they will be re-emitted as <link> in <head> by a separate
        // transformer.
        $ctx = new Context('/tmp/site');
        $html = $this->wrap('@import url("https://fonts.googleapis.com/css2?family=Roboto"); .x { color: red; }');
        $out = $this->apply($html, $ctx);
        self::assertStringNotContainsString('@import', $out);
        self::assertSame(['https://fonts.googleapis.com/css2?family=Roboto'], $ctx->fontImports);
    }

    #[Test]
    public function extractsMultipleAllowlistedFontImports(): void
    {
        $ctx = new Context('/tmp/site');
        $css = '@import url("https://fonts.googleapis.com/css?family=Roboto");'
            . '@import url("https://use.typekit.net/foo.css");'
            . '@import url("https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css");';
        $this->apply($this->wrap($css), $ctx);
        self::assertSame(
            [
                'https://fonts.googleapis.com/css?family=Roboto',
                'https://use.typekit.net/foo.css',
            ],
            $ctx->fontImports,
        );
    }

    #[Test]
    public function fontImportRegexHandlesSemicolonsInUrl(): void
    {
        // Google Fonts family=PT+Sans:wght@400;700 — the URL itself contains
        // `;` which the @import-strip regex must skip.
        $ctx = new Context('/tmp/site');
        $css = '@import url("https://fonts.googleapis.com/css2?family=PT+Sans:wght@400;700&display=swap"); .x { color: red; }';
        $out = $this->apply($this->wrap($css), $ctx);
        self::assertSame(
            ['https://fonts.googleapis.com/css2?family=PT+Sans:wght@400;700&display=swap'],
            $ctx->fontImports,
        );
        self::assertStringNotContainsString('@import', $out);
        self::assertStringContainsString('.x { color: red; }', $out);
    }

    #[Test]
    public function fontImportRegexHandlesQuotedFormWithoutUrl(): void
    {
        // CSS allows `@import "..."` without url() wrapper.
        $ctx = new Context('/tmp/site');
        $css = '@import "https://fonts.googleapis.com/css?family=Roboto";';
        $this->apply($this->wrap($css), $ctx);
        self::assertSame(['https://fonts.googleapis.com/css?family=Roboto'], $ctx->fontImports);
    }

    #[Test]
    public function neutralisesVendorPrefixedMediaFeatures(): void
    {
        $html = $this->wrap('@media (-moz-touch-enabled: 1) { .x { color: red; } }');
        $out = $this->apply($html);
        self::assertStringContainsString('@media (min-width: 0)', $out);
        self::assertStringNotContainsString('-moz-touch-enabled', $out);
    }

    #[Test]
    public function neutralisesWebkitMinDevicePixelRatio(): void
    {
        $html = $this->wrap('@media only screen and (-webkit-min-device-pixel-ratio: 2) { .x{} }');
        $out = $this->apply($html);
        self::assertStringContainsString('(min-width: 0)', $out);
        self::assertStringNotContainsString('-webkit-min-device-pixel-ratio', $out);
    }

    #[Test]
    public function stripsBrokenCustomPropertyWithOddSingleQuoteCount(): void
    {
        $html = $this->wrap(":root { --bad: 'unterminated; --ok: 12px; }");
        $out = $this->apply($html);
        self::assertStringContainsString('/* broken custom property removed */', $out);
        self::assertStringContainsString('--ok: 12px;', $out);
    }

    #[Test]
    public function stripsBrokenCustomPropertyWithOddDoubleQuoteCount(): void
    {
        $html = $this->wrap(':root { --bad: "unterminated; --ok: 12px; }');
        $out = $this->apply($html);
        self::assertStringContainsString('/* broken custom property removed */', $out);
        self::assertStringContainsString('--ok: 12px;', $out);
    }

    #[Test]
    public function preservesCustomPropertyWithBalancedQuotes(): void
    {
        $css = ":root { --quote: 'hi'; --ok: 12px; }";
        $out = $this->apply($this->wrap($css));
        self::assertStringContainsString("--quote: 'hi';", $out);
        self::assertStringContainsString('--ok: 12px;', $out);
        self::assertStringNotContainsString('broken custom property', $out);
    }

    #[Test]
    public function stripsImportantFromInlineStyleAttr(): void
    {
        $html = '<div style="color: red !important;">x</div>';
        self::assertSame('<div style="color: red;">x</div>', $this->apply($html));
    }

    #[Test]
    public function stripsImportantFromMultipleInlineStyleAttrs(): void
    {
        $html = '<div style="color: red !important;">a</div><span style="margin: 0 !important;">b</span>';
        $out = $this->apply($html);
        self::assertStringNotContainsString('!important', $out);
    }

    #[Test]
    public function leavesInlineStyleAttrWithoutImportantUnchanged(): void
    {
        $html = '<div style="color: red;">x</div>';
        self::assertSame($html, $this->apply($html));
    }

    #[Test]
    public function ampCustomBlockAbsentIsNoOpExceptForInlineStyle(): void
    {
        $html = '<html><body><div style="color: red !important;">hi</div></body></html>';
        $out = $this->apply($html);
        self::assertStringNotContainsString('!important', $out);
        self::assertStringContainsString('<div style="color: red;">hi</div>', $out);
    }

    #[Test]
    public function ampCustomBlockMatchCaseInsensitive(): void
    {
        $html = '<style amp-custom>.x { color: red !important; }</style>';
        $upper = '<STYLE amp-custom>.x { color: red !important; }</STYLE>';
        self::assertStringNotContainsString('!important', $this->apply($html));
        self::assertStringNotContainsString('!important', $this->apply($upper));
    }

    #[Test]
    public function preservesNonImportRulesAndComments(): void
    {
        $css = '/* keep me */ @import url("https://fonts.googleapis.com/x"); .x { color: red; } @media (min-width: 0) { .y {} }';
        $ctx = new Context('/tmp/site');
        $out = $this->apply($this->wrap($css), $ctx);
        self::assertStringContainsString('/* keep me */', $out);
        self::assertStringContainsString('.x { color: red; }', $out);
        self::assertStringContainsString('@media (min-width: 0)', $out);
        self::assertStringNotContainsString('@import', $out);
        self::assertSame(['https://fonts.googleapis.com/x'], $ctx->fontImports);
    }
}
