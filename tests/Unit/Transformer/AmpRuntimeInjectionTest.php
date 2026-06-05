<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer\AmpRuntimeInjection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AmpRuntimeInjectionTest extends TestCase
{
    /**
     * @return array{html: string, ctx: Context}
     */
    private function apply(string $html, ?Context $ctx = null): array
    {
        $ctx ??= new Context('/tmp/site');
        $out = (new AmpRuntimeInjection())->apply($html, $ctx);

        return ['html' => $out, 'ctx' => $ctx];
    }

    // === <html ⚡> ===

    #[Test]
    public function htmlOpenGetsAmpFlagWhenMissing(): void
    {
        $r = $this->apply('<html lang="ru"><head><meta charset="utf-8"></head><body></body></html>');
        self::assertStringContainsString('<html ⚡ lang="ru">', $r['html']);
    }

    #[Test]
    public function htmlOpenWithExistingAmpFlagIsLeftAlone(): void
    {
        $r = $this->apply('<html ⚡ lang="ru"><head><meta charset="utf-8"></head><body></body></html>');
        self::assertSame(1, substr_count($r['html'], '⚡'));
    }

    #[Test]
    public function htmlOpenWithAmpAttributeWordIsLeftAlone(): void
    {
        $r = $this->apply('<html amp lang="ru"><head><meta charset="utf-8"></head><body></body></html>');
        self::assertStringNotContainsString('⚡', $r['html']);
    }

    // === http-equiv → charset ===

    #[Test]
    public function httpEquivContentTypeBecomesMetaCharset(): void
    {
        $r = $this->apply('<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8"></head>');
        self::assertStringContainsString('<meta charset="utf-8">', $r['html']);
        self::assertStringNotContainsString('http-equiv', $r['html']);
    }

    // === orphan link self-closing ===

    #[Test]
    public function orphanLinkHrefBeforeMetaGetsSelfClosed(): void
    {
        $r = $this->apply('<head><link rel="stylesheet" href="/a.css"<meta charset="utf-8"></head>');
        self::assertStringContainsString('<link rel="stylesheet" href="/a.css"/>', $r['html']);
    }

    // === noscript guard ===

    #[Test]
    public function noscriptWithAmpBoilerplateIsKept(): void
    {
        $r = $this->apply('<head><meta charset="utf-8"><noscript><style amp-boilerplate>body{animation:none}</style></noscript></head>');
        self::assertStringContainsString('amp-boilerplate', $r['html']);
        self::assertStringContainsString('<noscript>', $r['html']);
    }

    #[Test]
    public function noscriptWithoutAmpBoilerplateIsDropped(): void
    {
        // The injected AMP boilerplate also contains a <noscript> wrapper,
        // so we check the SOURCE noscript content (the <img>) is gone
        // rather than counting <noscript> occurrences.
        $r = $this->apply('<head><meta charset="utf-8"><noscript><img src="/p.png"></noscript></head>');
        self::assertStringNotContainsString('/p.png', $r['html']);
    }

    // === Runtime injection ===

    #[Test]
    public function runtimeAndBoilerplateInjectedAfterMetaCharset(): void
    {
        $r = $this->apply('<head><meta charset="utf-8"></head>');
        self::assertStringContainsString('https://cdn.ampproject.org/v0.js', $r['html']);
        self::assertStringContainsString('amp-boilerplate', $r['html']);
        $charsetEnd = strpos($r['html'], '<meta charset="utf-8">') + strlen('<meta charset="utf-8">');
        $runtimePos = strpos($r['html'], 'v0.js');
        self::assertNotFalse($runtimePos);
        self::assertGreaterThan($charsetEnd, $runtimePos);
    }

    #[Test]
    public function runtimeInjectedAfterHeadOpenWhenNoMetaCharset(): void
    {
        $r = $this->apply('<head><title>x</title></head>');
        self::assertStringContainsString('v0.js', $r['html']);
        $headOpen = strpos($r['html'], '<head>');
        self::assertNotFalse($headOpen);
        $runtimePos = strpos($r['html'], 'v0.js');
        self::assertNotFalse($runtimePos);
        self::assertGreaterThan($headOpen, $runtimePos);
    }

    #[Test]
    public function customElementScriptsInjectedSortedForEachUsedComponent(): void
    {
        $ctx = new Context('/tmp/site');
        $ctx->markComponentUsed('amp-youtube');
        $ctx->markComponentUsed('amp-img'); // built-in into v0.js; must NOT get a script
        $ctx->markComponentUsed('amp-bind');
        $ctx->markComponentUsed('amp-accordion');
        $r = $this->apply('<head><meta charset="utf-8"></head>', $ctx);
        $accPos = strpos($r['html'], 'amp-accordion-0.1.js');
        $bindPos = strpos($r['html'], 'amp-bind-0.1.js');
        $ytPos = strpos($r['html'], 'amp-youtube-0.1.js');
        self::assertNotFalse($accPos);
        self::assertNotFalse($bindPos);
        self::assertNotFalse($ytPos);
        // amp-img is built-in to v0.js → no separate script.
        self::assertStringNotContainsString('amp-img-0.1.js', $r['html']);
        // Sorted alphabetically.
        self::assertLessThan($bindPos, $accPos);
        self::assertLessThan($ytPos, $bindPos);
    }

    #[Test]
    public function idempotentSkipsWhenRuntimeAlreadyPresent(): void
    {
        $html = '<head><meta charset="utf-8">' . "\n" . '<script async src="https://cdn.ampproject.org/v0.js"></script></head>';
        $r = $this->apply($html);
        self::assertSame(1, substr_count($r['html'], 'v0.js'));
    }

    #[Test]
    public function missingHeadAndCharsetEmitsWarning(): void
    {
        $r = $this->apply('<body>plain</body>');
        self::assertNotEmpty($r['ctx']->warnings);
        self::assertStringContainsString('AMP runtime NOT injected', $r['ctx']->warnings[0]);
    }

    // === Canonical link ===

    #[Test]
    public function canonicalLinkInjectedWhenMissing(): void
    {
        $r = $this->apply('<head><meta charset="utf-8"></head>');
        self::assertStringContainsString('<link rel="canonical" href="./">', $r['html']);
    }

    #[Test]
    public function existingCanonicalLinkIsLeftAlone(): void
    {
        $r = $this->apply('<head><meta charset="utf-8"><link rel="canonical" href="https://example.com/x"></head>');
        self::assertSame(1, substr_count($r['html'], 'rel="canonical"'));
        self::assertStringContainsString('href="https://example.com/x"', $r['html']);
    }
}
