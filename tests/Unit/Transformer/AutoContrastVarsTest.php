<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer\AutoContrastVars;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AutoContrastVarsTest extends TestCase
{
    private function apply(string $html): string
    {
        return (new AutoContrastVars())->apply($html, new Context('/tmp/site'));
    }

    private function wrap(string $css): string
    {
        return '<style amp-custom>' . $css . '</style>';
    }

    // === Strategy B: substitute --X:auto with luma-picked contrast ===

    #[Test]
    public function darkBodyBackgroundResolvesAutoToWhite(): void
    {
        $css = 'body{background:#111}'
            . ':root{--text-color:auto}'
            . '.label{color:var(--text-color)}';
        $out = $this->apply($this->wrap($css));
        self::assertStringContainsString('--text-color:#ffffff', $out);
        self::assertStringNotContainsString('--text-color:auto', $out);
    }

    #[Test]
    public function lightBodyBackgroundResolvesAutoToBlack(): void
    {
        $css = 'body{background:#eee}'
            . ':root{--text-color:auto}'
            . '.label{color:var(--text-color)}';
        $out = $this->apply($this->wrap($css));
        self::assertStringContainsString('--text-color:#000000', $out);
    }

    #[Test]
    public function htmlAsFallbackBackgroundWorks(): void
    {
        $css = 'html{background-color:#0a0a0a}'
            . ':root{--c:auto}'
            . '.x{color:var(--c)}';
        $out = $this->apply($this->wrap($css));
        self::assertStringContainsString('--c:#ffffff', $out);
    }

    #[Test]
    public function rootAsFallbackBackgroundWorks(): void
    {
        $css = ':root{background:#fff}'
            . ':root{--c:auto}'
            . '.x{color:var(--c)}';
        $out = $this->apply($this->wrap($css));
        self::assertStringContainsString('--c:#000000', $out);
    }

    #[Test]
    public function threeDigitHexIsExpandedToSixForLumaCalc(): void
    {
        // #fff → ffffff → light → black contrast.
        $css = 'body{background:#fff}'
            . ':root{--c:auto}'
            . '.x{color:var(--c)}';
        $out = $this->apply($this->wrap($css));
        self::assertStringContainsString('--c:#000000', $out);
    }

    #[Test]
    public function indirectBackgroundThroughVarResolves(): void
    {
        $css = ':root{--bg:#222;--c:auto}'
            . 'body{background:var(--bg)}'
            . '.x{color:var(--c)}';
        $out = $this->apply($this->wrap($css));
        self::assertStringContainsString('--c:#ffffff', $out);
    }

    #[Test]
    public function multipleAutoVarsAllGetSubstituted(): void
    {
        $css = 'body{background:#111}'
            . ':root{--text:auto;--icon:auto}'
            . '.x{color:var(--text)}.y{fill:var(--icon)}';
        $out = $this->apply($this->wrap($css));
        self::assertStringContainsString('--text:#ffffff', $out);
        self::assertStringContainsString('--icon:#ffffff', $out);
    }

    // === Strategy A: fallback strip when background isn't recoverable ===

    #[Test]
    public function fallbackStripsInvalidColorDeclWhenNoBackground(): void
    {
        $css = ':root{--c:auto}'
            . '.label{color:var(--c);font-size:14px}'
            . '.label{margin:0}';
        $out = $this->apply($this->wrap($css));
        // The invalid `color:var(--c)` declaration is gone.
        self::assertStringNotContainsString('color:var(--c)', $out);
        // The font-size on the same rule is preserved (we only strip the
        // matched decl).
        self::assertStringContainsString('font-size:14px', $out);
    }

    // === No-op cases ===

    #[Test]
    public function nonAmpCustomCssIsUntouched(): void
    {
        $html = '<div><style>.x{--c:auto;color:var(--c)}</style></div>';
        self::assertSame($html, $this->apply($html));
    }

    #[Test]
    public function autoVarUsedOnlyInWidthOrHeightIsLeftAlone(): void
    {
        // Width:auto is valid — must NOT resolve or strip.
        $css = 'body{background:#fff}'
            . ':root{--size:auto}'
            . '.x{width:var(--size)}';
        $out = $this->apply($this->wrap($css));
        self::assertStringContainsString('--size:auto', $out);
        self::assertStringContainsString('width:var(--size)', $out);
    }

    #[Test]
    public function noAutoVarsAtAllIsExactPassThrough(): void
    {
        $css = 'body{background:#fff}.x{color:#000}';
        self::assertSame($this->wrap($css), $this->apply($this->wrap($css)));
    }

    #[Test]
    public function ampCustomMissingIsExactPassThrough(): void
    {
        $html = '<html><body><p>hi</p></body></html>';
        self::assertSame($html, $this->apply($html));
    }

    // === Luma boundary ===

    #[Test]
    public function lumaBoundaryAtMidGreyPicksWhiteForDarker(): void
    {
        // YIQ for #404040: 64*(299+587+114)/1000 = 64 → < 128 → white text.
        $css = 'body{background:#404040}'
            . ':root{--c:auto}'
            . '.x{color:var(--c)}';
        $out = $this->apply($this->wrap($css));
        self::assertStringContainsString('--c:#ffffff', $out);
    }

    #[Test]
    public function lumaBoundaryAtMidGreyPicksBlackForLighter(): void
    {
        // #B0B0B0: 176*(...) ≈ 176 → > 128 → black text.
        $css = 'body{background:#b0b0b0}'
            . ':root{--c:auto}'
            . '.x{color:var(--c)}';
        $out = $this->apply($this->wrap($css));
        self::assertStringContainsString('--c:#000000', $out);
    }
}
