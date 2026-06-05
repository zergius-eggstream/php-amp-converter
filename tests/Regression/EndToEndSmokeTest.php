<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Regression;

use AmpConverter\AmpConverter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end smoke test against a real rendered page from a host project,
 * exercising every transformer at once: amp-img, amp-iframe, amp-bind
 * (burger), amp-accordion (FAQ), inlined external CSS, font @imports,
 * JSON-LD preservation, AMP runtime + boilerplate + canonical.
 *
 * Strictly developer-machine: reads the fixture directory from the
 * `AMP_CONVERTER_SMOKE_FIXTURE_DIR` environment variable. The directory
 * is expected to be a site root containing a rendered page at
 * `public/_data/pages/index.html` (and, optionally, a previously
 * converted reference at `public/_data/pages_amp/index.html` for the
 * size-delta guard). When the variable is unset or the path is missing,
 * the test auto-skips — CI just runs the unit tests.
 *
 * Set the variable in your shell or via phpunit's `<env>` config:
 *
 *     AMP_CONVERTER_SMOKE_FIXTURE_DIR=/path/to/site vendor/bin/phpunit
 */
final class EndToEndSmokeTest extends TestCase
{
    private string $siteRoot;
    private string $inputPath;

    protected function setUp(): void
    {
        $env = getenv('AMP_CONVERTER_SMOKE_FIXTURE_DIR');
        if ($env === false || $env === '') {
            self::markTestSkipped('AMP_CONVERTER_SMOKE_FIXTURE_DIR not set — smoke test skipped.');
        }
        $this->siteRoot = rtrim($env, '/\\');
        $this->inputPath = $this->siteRoot . '/public/_data/pages/index.html';
        if (!is_file($this->inputPath)) {
            self::markTestSkipped("Fixture page missing at {$this->inputPath} — smoke test skipped.");
        }
    }

    #[Test]
    public function realPageConvertsWithoutExceptions(): void
    {
        $html = (string) file_get_contents($this->inputPath);
        $result = AmpConverter::createDefault()->convert($html, $this->siteRoot);

        self::assertNotSame('', $result->html);
    }

    #[Test]
    public function realPageProducesValidAmpHeadMarkers(): void
    {
        $html = (string) file_get_contents($this->inputPath);
        $result = AmpConverter::createDefault()->convert($html, $this->siteRoot);

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
        $html = (string) file_get_contents($this->inputPath);
        $result = AmpConverter::createDefault()->convert($html, $this->siteRoot);

        // The smoke fixture is expected to exercise the full feature
        // surface: images, an embedded video, a generic iframe, a
        // burger menu and an FAQ section.
        foreach (['amp-img', 'amp-youtube', 'amp-iframe', 'amp-bind', 'amp-accordion'] as $component) {
            self::assertContains($component, $result->usedComponents, "missing detected component: {$component}");
        }
    }

    #[Test]
    public function realPagePreservesJsonLdSchemaBlock(): void
    {
        $html = (string) file_get_contents($this->inputPath);
        $result = AmpConverter::createDefault()->convert($html, $this->siteRoot);

        // The page is expected to ship schema.org JSON-LD that must
        // survive script-strip.
        self::assertStringContainsString('<script type="application/ld+json">', $result->html);
        self::assertStringContainsString('schema.org', $result->html);
    }

    #[Test]
    public function realPageOutputSizeStaysWithinAmpLimits(): void
    {
        $html = (string) file_get_contents($this->inputPath);
        $result = AmpConverter::createDefault()->convert($html, $this->siteRoot);

        // AMP requires <style amp-custom> to be <= 75 KB.
        if (preg_match('#<style amp-custom>([\s\S]*?)</style>#i', $result->html, $m) === 1) {
            self::assertLessThan(75 * 1024, strlen($m[1]), 'amp-custom block over AMP 75 KB limit');
        }
    }

    #[Test]
    public function realPageOutputSizeRoughlyMatchesReferenceBaseline(): void
    {
        $baseline = $this->siteRoot . '/public/_data/pages_amp/index.html';
        if (!is_file($baseline)) {
            self::markTestSkipped('No reference baseline at ' . $baseline);
        }
        $html = (string) file_get_contents($this->inputPath);
        $result = AmpConverter::createDefault()->convert($html, $this->siteRoot);

        // The byte count should be within 1% of the reference baseline
        // (whatever the host or upstream considers the source of truth).
        // Tighter byte-equality is tracked separately; this test guards
        // against major regressions only.
        $baselineSize = (int) filesize($baseline);
        $delta = abs(strlen($result->html) - $baselineSize);
        self::assertLessThan(
            (int) ($baselineSize * 0.01),
            $delta,
            "PHP output size differs from reference by {$delta} bytes (>1%)",
        );
    }
}
