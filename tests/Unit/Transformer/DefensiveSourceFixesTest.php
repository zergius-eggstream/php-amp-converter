<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer\DefensiveSourceFixes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DefensiveSourceFixesTest extends TestCase
{
    /**
     * @return array{html: string, ctx: Context}
     */
    private function apply(string $html): array
    {
        $ctx = new Context('/tmp/site');
        $out = (new DefensiveSourceFixes())->apply($html, $ctx);

        return ['html' => $out, 'ctx' => $ctx];
    }

    // === Script strip ===

    #[Test]
    public function stripsInlineScript(): void
    {
        $r = $this->apply('<p>hi</p><script>alert(1)</script><p>bye</p>');
        self::assertSame('<p>hi</p><p>bye</p>', $r['html']);
    }

    #[Test]
    public function stripsExternalScript(): void
    {
        $r = $this->apply('<script src="https://x.js"></script><p>x</p>');
        self::assertSame('<p>x</p>', $r['html']);
    }

    #[Test]
    public function stripsSelfClosingScript(): void
    {
        $r = $this->apply('<p>a</p><script src="https://x.js"/><p>b</p>');
        self::assertSame('<p>a</p><p>b</p>', $r['html']);
    }

    // === Inline event handlers ===

    #[Test]
    public function stripsOnclickDoubleQuotes(): void
    {
        $r = $this->apply('<a href="/x" onclick="foo()" class="y">L</a>');
        self::assertStringNotContainsString('onclick', $r['html']);
        self::assertStringContainsString('href="/x"', $r['html']);
        self::assertStringContainsString('class="y"', $r['html']);
    }

    #[Test]
    public function stripsOnclickSingleQuotes(): void
    {
        $r = $this->apply("<a onclick='foo()' class=\"y\">L</a>");
        self::assertStringNotContainsString('onclick', $r['html']);
    }

    #[Test]
    public function stripsAllOnHandlersIncludingOnloadOnchange(): void
    {
        $r = $this->apply('<div onload="a()" onchange="b()" class="x">y</div>');
        self::assertStringNotContainsString('onload', $r['html']);
        self::assertStringNotContainsString('onchange', $r['html']);
    }

    // === aria-roledescription ===

    #[Test]
    public function stripsAriaRoledescription(): void
    {
        $r = $this->apply('<div aria-roledescription="slide" class="x">y</div>');
        self::assertStringNotContainsString('aria-roledescription', $r['html']);
    }

    // === URL scheme typos ===

    #[Test]
    public function fixesHtpsTypoInHref(): void
    {
        $r = $this->apply('<a href="htps://example.com">x</a>');
        self::assertStringContainsString('href="https://example.com"', $r['html']);
    }

    #[Test]
    public function fixesHtsTypoInSrc(): void
    {
        $r = $this->apply('<img src="hts://example.com/x.png">');
        self::assertStringContainsString('src="https://example.com/x.png"', $r['html']);
    }

    #[Test]
    public function fixesTriplePlusTeeTypoInAction(): void
    {
        $r = $this->apply('<form action="htttps://example.com"></form>');
        self::assertStringContainsString('action="https://example.com"', $r['html']);
    }

    // === Broken <h2 text</h2> ===

    #[Test]
    public function fixesBrokenHeadingCloseMissingAngle(): void
    {
        $r = $this->apply('<h2 Some heading text</h2>');
        self::assertSame('<h2>Some heading text</h2>', $r['html']);
    }

    #[Test]
    public function fixesBrokenH3AndH4(): void
    {
        $r = $this->apply('<h3 a</h3><h4 b</h4>');
        self::assertStringContainsString('<h3>a</h3>', $r['html']);
        self::assertStringContainsString('<h4>b</h4>', $r['html']);
    }

    // === Duplicates ===

    #[Test]
    public function dropsDuplicateMetaCharset(): void
    {
        $r = $this->apply('<head><meta charset="utf-8"><meta charset="utf-8"></head>');
        self::assertSame(1, substr_count($r['html'], '<meta charset='));
        self::assertContains('duplicate meta charset removed', $r['ctx']->warnings);
    }

    #[Test]
    public function dropsDuplicateDoctype(): void
    {
        $r = $this->apply('<!DOCTYPE html><!doctype html><html></html>');
        self::assertSame(1, substr_count(strtolower($r['html']), '<!doctype'));
        self::assertContains('duplicate doctype removed', $r['ctx']->warnings);
    }

    #[Test]
    public function dropsNestedHtmlOpenTag(): void
    {
        $r = $this->apply('<html><html lang="ru"><head></head></html>');
        self::assertSame(1, substr_count($r['html'], '<html'));
        self::assertContains('nested <html> opening tag removed', $r['ctx']->warnings);
    }

    #[Test]
    public function dropsDuplicateHeadAndBody(): void
    {
        $r = $this->apply('<head><meta x="1"></head><head><title>z</title></head><body></body><body></body>');
        self::assertSame(1, substr_count($r['html'], '<head'));
        self::assertSame(1, substr_count($r['html'], '<body'));
        self::assertSame(1, substr_count($r['html'], '</head'));
        self::assertSame(1, substr_count($r['html'], '</body'));
    }

    // === Head/body cross-contamination ===

    #[Test]
    public function stripsBodyOnlyTagsFromHead(): void
    {
        $r = $this->apply('<head><meta charset="utf-8"><span class="wp-admin-bar">x</span><div>noise</div></head><body><p>hi</p></body>');
        self::assertStringNotContainsString('wp-admin-bar', $r['html']);
        self::assertStringNotContainsString('noise', $r['html']);
        self::assertStringContainsString('<meta charset="utf-8">', $r['html']);
        self::assertStringContainsString('<p>hi</p>', $r['html']);
    }

    #[Test]
    public function stripsHeadOnlyTagsFromBody(): void
    {
        $r = $this->apply('<body><p>hi</p><meta charset="utf-8"><link rel="stylesheet" href="/y.css"><title>oops</title></body>');
        self::assertStringContainsString('<p>hi</p>', $r['html']);
        // Stripping inside body: those should be gone (the only meta/title
        // that survived was originally in body).
        self::assertStringNotContainsString('<title>', $r['html']);
        self::assertStringNotContainsString('<link rel', $r['html']);
    }

    // === Table border ===

    #[Test]
    public function tableBorderNonNumericBecomesZero(): void
    {
        $r = $this->apply('<table border="3px black"><tr><td>x</td></tr></table>');
        self::assertStringContainsString('border="0"', $r['html']);
    }

    #[Test]
    public function tableBorderNumericLeftAlone(): void
    {
        $r = $this->apply('<table class="x" border="1"><tr></tr></table>');
        self::assertStringContainsString('border="1"', $r['html']);
    }

    // === rel dedupe on <a> ===

    #[Test]
    public function dedupesRepeatedRelOnAnchor(): void
    {
        $r = $this->apply('<a href="/x" rel="nofollow" rel="noopener">x</a>');
        self::assertSame(1, substr_count($r['html'], 'rel='));
        self::assertStringContainsString('rel="nofollow noopener"', $r['html']);
    }

    #[Test]
    public function dedupesRelValuesWithinAttr(): void
    {
        $r = $this->apply('<a href="/x" rel="nofollow noopener" rel="nofollow">x</a>');
        self::assertStringContainsString('rel="nofollow noopener"', $r['html']);
    }

    #[Test]
    public function singleRelOnAnchorIsLeftAlone(): void
    {
        $r = $this->apply('<a href="/x" rel="noopener">x</a>');
        self::assertSame('<a href="/x" rel="noopener">x</a>', $r['html']);
    }

    // === class dedupe ===

    #[Test]
    public function dedupesRepeatedClassAttrOnDiv(): void
    {
        $r = $this->apply('<div class="a b" id="x" class="c">y</div>');
        self::assertSame(1, substr_count($r['html'], 'class='));
        self::assertStringContainsString('class="a b c"', $r['html']);
        self::assertStringContainsString('id="x"', $r['html']);
    }

    #[Test]
    public function classDedupeRemovesDuplicatesInValue(): void
    {
        $r = $this->apply('<div class="a b" class="b c">y</div>');
        self::assertStringContainsString('class="a b c"', $r['html']);
    }

    // === alt/loading/srcset on non-media ===

    #[Test]
    public function stripsAltOnDiv(): void
    {
        $r = $this->apply('<div alt="hi" class="x">y</div>');
        self::assertStringNotContainsString('alt=', $r['html']);
        self::assertStringContainsString('class="x"', $r['html']);
    }

    #[Test]
    public function stripsLoadingAndSrcsetOnSpan(): void
    {
        $r = $this->apply('<span loading="lazy" srcset="/a 1x" id="x">y</span>');
        self::assertStringNotContainsString('loading=', $r['html']);
        self::assertStringNotContainsString('srcset=', $r['html']);
        self::assertStringContainsString('id="x"', $r['html']);
    }

    #[Test]
    public function preservesAltOnImg(): void
    {
        $r = $this->apply('<img src="/x.png" alt="hi" loading="lazy">');
        self::assertStringContainsString('alt="hi"', $r['html']);
        self::assertStringContainsString('loading="lazy"', $r['html']);
    }

    #[Test]
    public function preservesAltOnAmpImg(): void
    {
        $r = $this->apply('<amp-img src="/x.png" alt="hi" layout="responsive"></amp-img>');
        self::assertStringContainsString('alt="hi"', $r['html']);
    }

    // === preload ===

    #[Test]
    public function stripsLinkRelPreload(): void
    {
        $r = $this->apply('<link rel="preload" href="/x.css" as="style"><link rel="stylesheet" href="/y.css">');
        self::assertStringNotContainsString('rel="preload"', $r['html']);
        self::assertStringContainsString('rel="stylesheet"', $r['html']);
    }

    #[Test]
    public function stripsStandalonePreloadAttrOnLink(): void
    {
        $r = $this->apply('<link rel="stylesheet" href="/y.css" preload>');
        self::assertStringNotContainsString('preload', $r['html']);
        self::assertStringContainsString('rel="stylesheet"', $r['html']);
    }

    // === Oversized inline style ===

    #[Test]
    public function stripsInlineStyleOver1000Bytes(): void
    {
        $bigCss = str_repeat('a', 1500);
        $r = $this->apply('<div class="x" style="' . $bigCss . '">y</div>');
        self::assertStringNotContainsString('style=', $r['html']);
        self::assertStringContainsString('class="x"', $r['html']);
        self::assertNotEmpty($r['ctx']->warnings);
        self::assertStringContainsString('1500 bytes > 1000 stripped', $r['ctx']->warnings[0]);
    }

    #[Test]
    public function preservesSmallInlineStyle(): void
    {
        $r = $this->apply('<div class="x" style="color:red">y</div>');
        self::assertStringContainsString('style="color:red"', $r['html']);
        self::assertSame([], $r['ctx']->warnings);
    }
}
