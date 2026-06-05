<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Regression;

use AmpConverter\AmpConverter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test on a real-world page (melada.kz) that exercises
 * almost every transformer at once: amp-img, amp-iframe, amp-bind
 * (burger), amp-accordion (FAQ), inlined external CSS, font @import,
 * JSON-LD preservation, AMP runtime + boilerplate + canonical.
 *
 * The page is shipped via the seo-sites checkout next to this repo at
 * `c:/output/seo-sites/data/sites/me/melada.kz/`. When that checkout is
 * absent (CI without the sibling repo), the test is skipped — this
 * remains a developer-machine regression rather than a CI gate.
 */
final class MeladaKzSmokeTest extends TestCase
{
    private const SITE_ROOT = 'c:/output/seo-sites/data/sites/me/melada.kz';
    private const INPUT = self::SITE_ROOT . '/public/_data/pages/index.html';

    protected function setUp(): void
    {
        if (!is_file(self::INPUT)) {
            self::markTestSkipped('Sibling seo-sites checkout not present — smoke test skipped.');
        }
    }

    #[Test]
    public function realPageConvertsWithoutExceptions(): void
    {
        $html = (string) file_get_contents(self::INPUT);
        $result = AmpConverter::createDefault()->convert($html, self::SITE_ROOT);

        self::assertNotSame('', $result->html);
    }

    #[Test]
    public function realPageProducesValidAmpHeadMarkers(): void
    {
        $html = (string) file_get_contents(self::INPUT);
        $result = AmpConverter::createDefault()->convert($html, self::SITE_ROOT);

        self::assertStringContainsString('<html ⚡', $result->html);
        self::assertStringContainsString('cdn.ampproject.org/v0.js', $result->html);
        self::assertStringContainsString('amp-boilerplate', $result->html);
        self::assertStringContainsString('<link rel="canonical"', $result->html);
        // amp-img is built into v0.js — must NOT have a script for it.
        self::assertStringNotContainsString('amp-img-0.1.js', $result->html);
    }

    #[Test]
    public function realPageDetectsAllComponentsCorrectly(): void
    {
        $html = (string) file_get_contents(self::INPUT);
        $result = AmpConverter::createDefault()->convert($html, self::SITE_ROOT);

        // melada.kz has imgs (amp-img), a YouTube embed (amp-youtube),
        // an iframe (amp-iframe), a burger menu (amp-bind) and an FAQ
        // section (amp-accordion).
        foreach (['amp-img', 'amp-youtube', 'amp-iframe', 'amp-bind', 'amp-accordion'] as $component) {
            self::assertContains($component, $result->usedComponents, "missing detected component: {$component}");
        }
    }

    #[Test]
    public function realPagePreservesJsonLdSchemaBlock(): void
    {
        $html = (string) file_get_contents(self::INPUT);
        $result = AmpConverter::createDefault()->convert($html, self::SITE_ROOT);

        // The page ships schema.org JSON-LD that must survive script-strip.
        self::assertStringContainsString('<script type="application/ld+json">', $result->html);
        self::assertStringContainsString('schema.org', $result->html);
    }

    #[Test]
    public function realPageOutputSizeStaysWithinAmpLimits(): void
    {
        $html = (string) file_get_contents(self::INPUT);
        $result = AmpConverter::createDefault()->convert($html, self::SITE_ROOT);

        // AMP requires <style amp-custom> to be <= 75 KB.
        if (preg_match('#<style amp-custom>([\s\S]*?)</style>#i', $result->html, $m) === 1) {
            self::assertLessThan(75 * 1024, strlen($m[1]), 'amp-custom block over AMP 75 KB limit');
        }
    }

    #[Test]
    public function realPageOutputSizeRoughlyMatchesNodeBaseline(): void
    {
        $baseline = self::SITE_ROOT . '/public/_data/pages_amp/index.html';
        if (!is_file($baseline)) {
            self::markTestSkipped('Node baseline file not present.');
        }
        $html = (string) file_get_contents(self::INPUT);
        $result = AmpConverter::createDefault()->convert($html, self::SITE_ROOT);

        // The byte count should be within 1% of the Node reference. Tighter
        // byte-equality is tracked separately; this test guards against
        // major regressions only.
        $baselineSize = (int) filesize($baseline);
        $delta = abs(strlen($result->html) - $baselineSize);
        self::assertLessThan(
            (int) ($baselineSize * 0.01),
            $delta,
            "PHP output size differs from Node baseline by {$delta} bytes (>1%)",
        );
    }
}
