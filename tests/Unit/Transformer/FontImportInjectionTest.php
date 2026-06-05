<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer\FontImportInjection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FontImportInjectionTest extends TestCase
{
    private function ctxWith(string ...$urls): Context
    {
        $ctx = new Context('/tmp/site');
        $ctx->fontImports = array_values($urls);

        return $ctx;
    }

    #[Test]
    public function noOpWhenNoFontImportsInContext(): void
    {
        $html = '<html><head><title>x</title></head><body></body></html>';
        $ctx = new Context('/tmp/site');
        self::assertSame($html, (new FontImportInjection())->apply($html, $ctx));
    }

    #[Test]
    public function injectsLinkBeforeClosingHead(): void
    {
        $html = '<html><head><title>x</title></head><body></body></html>';
        $ctx = $this->ctxWith('https://fonts.googleapis.com/css?family=Roboto');
        $out = (new FontImportInjection())->apply($html, $ctx);
        self::assertStringContainsString(
            '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Roboto">',
            $out,
        );
        // <link> must come before </head>, not after.
        $linkPos = strpos($out, '<link rel="stylesheet"');
        $headEnd = strpos($out, '</head>');
        self::assertNotFalse($linkPos);
        self::assertNotFalse($headEnd);
        self::assertLessThan($headEnd, $linkPos);
    }

    #[Test]
    public function injectsMultipleLinksInOrder(): void
    {
        $html = '<html><head></head><body></body></html>';
        $ctx = $this->ctxWith(
            'https://fonts.googleapis.com/css?family=A',
            'https://fonts.googleapis.com/css?family=B',
        );
        $out = (new FontImportInjection())->apply($html, $ctx);
        $aPos = strpos($out, 'family=A');
        $bPos = strpos($out, 'family=B');
        self::assertNotFalse($aPos);
        self::assertNotFalse($bPos);
        self::assertLessThan($bPos, $aPos);
    }

    #[Test]
    public function dedupesIdenticalUrlsPreservingFirstOccurrence(): void
    {
        $html = '<html><head></head><body></body></html>';
        $ctx = $this->ctxWith(
            'https://fonts.googleapis.com/css?family=A',
            'https://fonts.googleapis.com/css?family=A',
            'https://fonts.googleapis.com/css?family=B',
            'https://fonts.googleapis.com/css?family=A',
        );
        $out = (new FontImportInjection())->apply($html, $ctx);
        self::assertSame(2, substr_count($out, '<link rel="stylesheet"'));
        self::assertSame(1, substr_count($out, 'family=A'));
        self::assertSame(1, substr_count($out, 'family=B'));
    }

    #[Test]
    public function fallsBackToInjectAfterMetaCharsetWhenHeadCloseAbsent(): void
    {
        $html = '<meta charset="utf-8"><body>x</body>';
        $ctx = $this->ctxWith('https://fonts.googleapis.com/css?family=Roboto');
        $out = (new FontImportInjection())->apply($html, $ctx);
        self::assertStringContainsString('<link rel="stylesheet"', $out);
        $charsetPos = strpos($out, '<meta charset=');
        $linkPos = strpos($out, '<link rel="stylesheet"');
        self::assertNotFalse($charsetPos);
        self::assertNotFalse($linkPos);
        self::assertLessThan($linkPos, $charsetPos);
    }

    #[Test]
    public function metaCharsetFallbackTolerantOfSingleQuotesAndSelfClosing(): void
    {
        $html = "<meta charset='UTF-8' /><body></body>";
        $ctx = $this->ctxWith('https://fonts.googleapis.com/css?family=Roboto');
        $out = (new FontImportInjection())->apply($html, $ctx);
        self::assertStringContainsString('<link rel="stylesheet"', $out);
    }

    #[Test]
    public function noOpIfNeitherHeadCloseNorMetaCharsetPresent(): void
    {
        $html = '<body>x</body>';
        $ctx = $this->ctxWith('https://fonts.googleapis.com/css?family=Roboto');
        $out = (new FontImportInjection())->apply($html, $ctx);
        self::assertSame($html, $out);
    }

    #[Test]
    public function prefersHeadCloseOverMetaCharsetWhenBothPresent(): void
    {
        $html = '<head><meta charset="utf-8"><title>x</title></head><body></body>';
        $ctx = $this->ctxWith('https://fonts.googleapis.com/css?family=Roboto');
        $out = (new FontImportInjection())->apply($html, $ctx);
        // The <link> must land before </head>, not right after <meta charset>.
        $charsetEnd = strpos($out, '<meta charset="utf-8">') + strlen('<meta charset="utf-8">');
        $linkPos = strpos($out, '<link rel="stylesheet"');
        $titlePos = strpos($out, '<title>');
        self::assertNotFalse($linkPos);
        self::assertNotFalse($titlePos);
        // If we'd taken the meta-charset path the link would sit between
        // <meta charset> and <title>. Verify it doesn't.
        self::assertGreaterThan($titlePos, $linkPos);
        self::assertGreaterThan($charsetEnd, $linkPos);
    }
}
