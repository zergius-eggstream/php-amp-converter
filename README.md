# php-amp-converter

Convert rendered HTML pages into AMP-valid HTML — pure PHP, no Node runtime.

PHP port of the Node-based `convert-rendered-to-amp.js` shipped with the [amp-seo-sites toolset](https://github.com/zergius-eggstream/amp-seo-sites). Designed for build-time conversion of static-rendered HTML (Symfony / cms.exe / standalone) into AMP HTML that passes the official `amphtml-validator`.

## Status

**Port complete, ready for review.** All 13 algorithm stages implemented and exercised end-to-end on a real-world ~119 KB customer page (melada.kz: amp-img, amp-youtube, amp-iframe, amp-bind burger, amp-accordion FAQ, inlined external CSS, JSON-LD); PHP output sits within **0.1 % of the Node reference** (113,628 vs 113,749 bytes, structurally identical AMP). 249 phpunit tests / 501 assertions; PHPStan level 8 clean. CI runs on PHP 8.4. Stage map: [`doc/port-status.md`](doc/port-status.md).

## Requirements

- PHP `>=8.4` (will be lowered if no 8.x features end up being required)
- `ext-libxml`, `ext-mbstring`, `ext-simplexml`, `ext-gd` (raster image dimensions; SVG uses ext-simplexml)
- **No Node.js** — pure PHP

## Installation

```bash
composer require zergius-eggstream/amp-converter
```

Once the maintainer of the consuming projects forks/republishes the package under their preferred vendor, that `require` line points at the new name (the namespace stays `AmpConverter\` so call sites don't change).

## Usage

```php
use AmpConverter\AmpConverter;

$result = AmpConverter::createDefault()->convert($renderedHtml, $siteRoot);
file_put_contents($outputPath, $result->html);
foreach ($result->warnings as $w) {
    error_log("amp-converter: $w");
}
```

`$siteRoot` is the absolute path to the **site directory** (the package looks for assets under `$siteRoot/public/`). Used to resolve relative image paths so dimensions can be read from disk.

`ConversionResult` exposes:

- `html: string` — the converted AMP HTML.
- `usedComponents: list<string>` — AMP custom components actually emitted (`amp-img`, `amp-youtube`, `amp-iframe`, `amp-bind`, `amp-accordion`, …). The pipeline emits a `<script async custom-element="…">` for each one (except `amp-img`, which ships with v0.js).
- `warnings: list<string>` — non-fatal issues encountered (unresolvable image, dropped CSS block, malformed tag, …). The host project decides whether to log, surface or ignore them.

### Symfony integration

The package is framework-agnostic. The seo-sites project wires it through a thin wrapper:

```php
// src/Renderer/AmpConverter.php  (one-line autowired service)
namespace App\Renderer;

use AmpConverter\AmpConverter as Lib;

readonly class AmpConverter
{
    public function convert(string $renderedHtml, string $siteRoot): string
    {
        return Lib::createDefault()->convert($renderedHtml, $siteRoot)->html;
    }
}
```

If a future iteration ships a Symfony Bundle for zero-config auto-wiring (`Bridge/Symfony/AmpConverterBundle`), it will be additive — the framework-agnostic core won't change.

## Architecture

The converter is an ordered **pipeline of transformers**. Each transformer implements `Transformer::apply(string $html, Context $ctx): string` and owns one feature area; `Context` carries cross-transformer state (siteRoot, used components, warnings, detected FAQ classes, font @imports collected from CSS, …).

```
input HTML
    │
    ▼
┌──────────────────────────┐
│ MaskSnippets             │  preserve Twig/PHP dynamics behind opaque placeholders
├──────────────────────────┤
│ CssAggregation           │  inline local <link rel=stylesheet>, merge <style> blocks
├──────────────────────────┤
│ CssProcessing            │  HTML-entity decode, font @import extract, strip
│                          │   !important / @import / @charset, vendor-media,
│                          │   broken --vars
├──────────────────────────┤
│ ImgToAmpImg              │  <img> → <amp-img> with layout pick (fixed/intrinsic/
│                          │   responsive/fill) + logo/avatar heuristics
├──────────────────────────┤
│ IframeConversion         │  YouTube → <amp-youtube>; other → <amp-iframe>
│                          │   (responsive / fixed-height / fill); <canvas> dropped
├──────────────────────────┤
│ FormConversion           │  <form> → <div data-was-form>, submit → amp-bind tap
├──────────────────────────┤
│ DefensiveSourceFixes     │  script strip (preserves JSON-LD), on*= strip,
│                          │   URL typos, duplicate doctype / meta / head / body,
│                          │   table border, rel/class dedupe, alt/loading guards,
│                          │   preload strip, oversized inline style
├──────────────────────────┤
│ BurgerToAmpBind          │  3-tier detection (aria-controls / class+nav /
│                          │   nav-driven) + CSS-pair guard (5 hidden / 5 shown)
├──────────────────────────┤
│ FaqToAccordion           │  4 variants (container + dl + sibling Question +
│                          │   hN+p) + CSS post-process (accordion patch,
│                          │   specificity bump, question-class defaults)
├──────────────────────────┤
│ AutoContrastVars         │  resolve --X:auto via YIQ luma; fallback strip
├──────────────────────────┤
│ FontImportInjection      │  emit <link rel=stylesheet> for collected font CDNs
├──────────────────────────┤
│ AmpRuntimeInjection      │  <html ⚡>, v0.js, custom-element scripts (sorted),
│                          │   boilerplate, canonical, http-equiv→charset,
│                          │   noscript guard
├──────────────────────────┤
│ PurgeCss                 │  shrink <style amp-custom> (60 KB threshold);
│                          │   recursive @media, @font-face/@keyframes preserved
├──────────────────────────┤
│ UnmaskSnippets           │  restore the dynamics from step 1
└──────────────────────────┘
    │
    ▼
ConversionResult { html, usedComponents, warnings }
```

Replace the default pipeline by constructing `AmpConverter` directly:

```php
use AmpConverter\AmpConverter;
use AmpConverter\Transformer\ImgToAmpImg;
use AmpConverter\PhpSnippets\MaskSnippets;
use AmpConverter\PhpSnippets\UnmaskSnippets;

$converter = new AmpConverter([
    new MaskSnippets(),
    new ImgToAmpImg(),
    new MyCustomTransformer(),
    new UnmaskSnippets(),
]);
```

## Error handling

Strict but graceful: when the converter cannot transform a fragment (unparseable `<img>` tag, malformed CSS block, missing image dimensions), it **removes the fragment and records a warning** rather than throwing. The whole page still converts. Build pipelines decide what to do with warnings (log / fail / ignore). Exceptions are reserved for unrecoverable programmer errors (e.g. invalid pipeline configuration).

## Testing

```bash
composer test       # phpunit
composer phpstan    # static analysis
```

Layout:

- `tests/Unit/<Area>/<Class>Test.php` — per-rule unit tests for every transformer; each spec rule has its own positive + negative test.
- `tests/Regression/MeladaKzSmokeTest.php` — end-to-end smoke test on a real customer page. Auto-skips when the sibling `seo-sites` checkout isn't present (CI just runs the unit tests).

CI is GitHub Actions over PHP 8.4 with the `libxml`, `mbstring`, `simplexml`, `gd` extensions; no Node required.

## License

MIT.
