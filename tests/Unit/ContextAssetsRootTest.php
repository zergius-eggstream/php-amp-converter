<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit;

use AmpConverter\Context;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContextAssetsRootTest extends TestCase
{
    #[Test]
    public function assetsRootDefaultsToSiteRootSlashPublic(): void
    {
        $ctx = new Context('/var/www/site');
        self::assertSame('/var/www/site' . DIRECTORY_SEPARATOR . 'public', $ctx->assetsRoot());
    }

    #[Test]
    public function emptyAssetsBaseDirCollapsesToSiteRoot(): void
    {
        // Flat layout: assets live directly under the site root, no `public/`
        // (or any other) subdir.
        $ctx = new Context('/var/www/site', assetsBaseDir: '');
        self::assertSame('/var/www/site', $ctx->assetsRoot());
    }

    #[Test]
    public function customAssetsBaseDirIsRespected(): void
    {
        $ctx = new Context('/var/www/site', assetsBaseDir: 'htdocs');
        self::assertSame('/var/www/site' . DIRECTORY_SEPARATOR . 'htdocs', $ctx->assetsRoot());
    }

    #[Test]
    public function trailingSlashesInBothPartsAreStripped(): void
    {
        $ctx = new Context('/var/www/site/', assetsBaseDir: '/htdocs/');
        self::assertSame('/var/www/site' . DIRECTORY_SEPARATOR . 'htdocs', $ctx->assetsRoot());
    }

    #[Test]
    public function canonicalUrlDefaultsToNull(): void
    {
        self::assertNull((new Context('/site'))->canonicalUrl);
    }

    #[Test]
    public function canonicalUrlIsReadable(): void
    {
        $ctx = new Context('/site', canonicalUrl: 'https://example.com/p');
        self::assertSame('https://example.com/p', $ctx->canonicalUrl);
    }
}
