<?php

declare(strict_types=1);

namespace ZergiusEggstream\AmpConverter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZergiusEggstream\AmpConverter\Context;

final class ContextTest extends TestCase
{
    #[Test]
    public function fresh_context_has_empty_collections(): void
    {
        $ctx = new Context(siteRoot: '/site');

        self::assertSame('/site', $ctx->siteRoot);
        self::assertSame([], $ctx->usedComponents);
        self::assertSame([], $ctx->warnings);
        self::assertSame([], $ctx->fontImports);
        self::assertSame([], $ctx->snippetStash);
        self::assertSame([], $ctx->faqQuestionClasses);
    }

    #[Test]
    public function mark_component_used_dedupes_via_array_key(): void
    {
        $ctx = new Context(siteRoot: '/site');
        $ctx->markComponentUsed('amp-img');
        $ctx->markComponentUsed('amp-img');
        $ctx->markComponentUsed('amp-iframe');

        self::assertSame(['amp-img', 'amp-iframe'], array_keys($ctx->usedComponents));
    }

    #[Test]
    public function add_warning_appends_in_order(): void
    {
        $ctx = new Context(siteRoot: '/site');
        $ctx->addWarning('first');
        $ctx->addWarning('second');

        self::assertSame(['first', 'second'], $ctx->warnings);
    }
}
