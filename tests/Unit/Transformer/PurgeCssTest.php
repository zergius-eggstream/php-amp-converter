<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer\PurgeCss;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PurgeCssTest extends TestCase
{
    private function apply(string $html): string
    {
        return (new PurgeCss())->apply($html, new Context('/tmp/site'));
    }

    /**
     * Produce a CSS string padded above the 60 KB threshold.
     * Each repeat = ~120 bytes, 600 repeats ≈ 72 KB > 60 KB.
     * The padding selectors deliberately match nothing in the test HTML so
     * they themselves get purged — leaving only what the rule under test
     * decided about the prefix.
     */
    private function pad(string $base): string
    {
        $junk = str_repeat(
            '.tossable-' . substr(md5($base), 0, 4) . '{color:red;padding:1px;margin:2px;border:0;font-size:14px}',
            1500,
        );

        return $base . $junk;
    }

    #[Test]
    public function smallStylesheetIsLeftUntouched(): void
    {
        $html = '<style amp-custom>.unused{color:red}</style><body></body>';
        self::assertSame($html, $this->apply($html));
    }

    #[Test]
    public function unusedClassDroppedAboveThreshold(): void
    {
        $css = $this->pad('.in-use{color:red}.never-used{color:green}');
        $html = '<style amp-custom>' . $css . '</style><div class="in-use"></div>';
        $out = $this->apply($html);
        self::assertStringContainsString('.in-use', $out);
        self::assertStringNotContainsString('.never-used', $out);
    }

    #[Test]
    public function usedIdSurvivesPurge(): void
    {
        $css = $this->pad('#header{color:red}#footer{color:green}');
        $html = '<style amp-custom>' . $css . '</style><div id="header"></div>';
        $out = $this->apply($html);
        self::assertStringContainsString('#header', $out);
        self::assertStringNotContainsString('#footer', $out);
    }

    #[Test]
    public function usedTagSurvivesPurge(): void
    {
        $css = $this->pad('section{padding:0}article{padding:1px}');
        $html = '<style amp-custom>' . $css . '</style><section></section>';
        $out = $this->apply($html);
        self::assertStringContainsString('section', $out);
        self::assertStringNotContainsString('article', $out);
    }

    #[Test]
    public function alwaysSafeSelectorsArePreserved(): void
    {
        $css = $this->pad('*{box-sizing:border-box}:root{--c:#fff}::placeholder{color:#999}.unused{x:1}');
        $html = '<style amp-custom>' . $css . '</style><body></body>';
        $out = $this->apply($html);
        self::assertStringContainsString('*{box-sizing', $out);
        self::assertStringContainsString(':root', $out);
        self::assertStringContainsString('::placeholder', $out);
        self::assertStringNotContainsString('.unused', $out);
    }

    #[Test]
    public function fontFaceAndKeyframesAreNeverPurged(): void
    {
        $css = $this->pad('@font-face{font-family:X;src:url(/x)}@keyframes spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}.unused-class{x:1}');
        $html = '<style amp-custom>' . $css . '</style><body></body>';
        $out = $this->apply($html);
        self::assertStringContainsString('@font-face', $out);
        self::assertStringContainsString('@keyframes spin', $out);
        self::assertStringNotContainsString('.unused-class', $out);
    }

    #[Test]
    public function mediaBlockIsRecursivelyPurged(): void
    {
        $css = $this->pad('@media (min-width:600px){.used{color:red}.unused-inside{color:green}}');
        $html = '<style amp-custom>' . $css . '</style><div class="used"></div>';
        $out = $this->apply($html);
        self::assertStringContainsString('.used', $out);
        self::assertStringNotContainsString('.unused-inside', $out);
        self::assertStringContainsString('@media', $out);
    }

    #[Test]
    public function emptyMediaBlockIsDroppedAfterRecursivePurge(): void
    {
        $css = $this->pad('@media (min-width:600px){.unused-a{x:1}.unused-b{x:2}}');
        $html = '<style amp-custom>' . $css . '</style><body></body>';
        $out = $this->apply($html);
        self::assertStringNotContainsString('@media', $out);
    }

    #[Test]
    public function commentsAreStripped(): void
    {
        $css = $this->pad('/* author note */.in-use{color:red}/* another */');
        $html = '<style amp-custom>' . $css . '</style><div class="in-use"></div>';
        $out = $this->apply($html);
        self::assertStringNotContainsString('author note', $out);
        self::assertStringNotContainsString('another', $out);
    }

    #[Test]
    public function commaSelectorListKeepsRuleIfAnyComponentMatches(): void
    {
        $css = $this->pad('.alpha,.beta,.gamma{color:red}');
        $html = '<style amp-custom>' . $css . '</style><div class="beta"></div>';
        $out = $this->apply($html);
        // The rule survives intact (any-of-list is enough).
        self::assertStringContainsString('.alpha,.beta,.gamma', $out);
    }
}
