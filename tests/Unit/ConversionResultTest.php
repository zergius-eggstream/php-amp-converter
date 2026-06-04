<?php

declare(strict_types=1);

namespace ZergiusEggstream\AmpConverter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZergiusEggstream\AmpConverter\ConversionResult;

final class ConversionResultTest extends TestCase
{
    #[Test]
    public function exposes_constructor_args_as_readonly_properties(): void
    {
        $result = new ConversionResult(
            html: '<html>x</html>',
            usedComponents: ['amp-img'],
            warnings: ['note'],
        );

        self::assertSame('<html>x</html>', $result->html);
        self::assertSame(['amp-img'], $result->usedComponents);
        self::assertSame(['note'], $result->warnings);
    }

    #[Test]
    public function has_warnings_reports_true_when_warnings_present(): void
    {
        $result = new ConversionResult(html: '', usedComponents: [], warnings: ['oops']);

        self::assertTrue($result->hasWarnings());
    }

    #[Test]
    public function has_warnings_reports_false_when_no_warnings(): void
    {
        $result = new ConversionResult(html: '', usedComponents: [], warnings: []);

        self::assertFalse($result->hasWarnings());
    }
}
