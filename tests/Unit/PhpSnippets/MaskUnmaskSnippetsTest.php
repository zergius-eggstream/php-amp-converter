<?php

declare(strict_types=1);

namespace ZergiusEggstream\AmpConverter\Tests\Unit\PhpSnippets;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZergiusEggstream\AmpConverter\Context;
use ZergiusEggstream\AmpConverter\PhpSnippets\MaskSnippets;
use ZergiusEggstream\AmpConverter\PhpSnippets\UnmaskSnippets;

final class MaskUnmaskSnippetsTest extends TestCase
{
    #[Test]
    public function mask_transformer_stashes_into_context(): void
    {
        $ctx = new Context(siteRoot: '/dev/null');
        $masked = (new MaskSnippets())->apply('<p>{{ x }}</p>', $ctx);

        self::assertSame('<p>xampphsZ0phsEND</p>', $masked);
        self::assertSame(['{{ x }}'], $ctx->snippetStash);
    }

    #[Test]
    public function unmask_transformer_restores_from_context(): void
    {
        $ctx = new Context(siteRoot: '/dev/null');
        $ctx->snippetStash = ['{{ x }}'];

        $restored = (new UnmaskSnippets())->apply('<p>xampphsZ0phsEND</p>', $ctx);

        self::assertSame('<p>{{ x }}</p>', $restored);
    }

    #[Test]
    public function mask_unmask_roundtrip_through_context(): void
    {
        $ctx = new Context(siteRoot: '/dev/null');
        $original = '<a href="{{ url }}">{% if cond %}label{% endif %}</a>';

        $masked = (new MaskSnippets())->apply($original, $ctx);
        $restored = (new UnmaskSnippets())->apply($masked, $ctx);

        self::assertSame($original, $restored);
    }
}
