# php-amp-converter

Convert rendered HTML pages into AMP-valid HTML — pure PHP, no Node runtime.

PHP port of the Node-based `convert-rendered-to-amp.js` shipped with the [amp-seo-sites toolset](https://github.com/zergius-eggstream/amp-seo-sites). Designed for build-time conversion of static-rendered HTML (Symfony / cms.exe / standalone) into AMP HTML that passes the official `amphtml-validator`.

## Status

🚧 **Work in progress.** Port follows the algorithm specification in [`doc/rendered-to-amp-algorithm.md`](https://github.com/zergius-eggstream/amp-seo-sites/blob/main/dist/amp-converter-v0.12/doc/rendered-to-amp-algorithm.md). Track progress in [`doc/port-status.md`](doc/port-status.md).

## Requirements

- PHP `>=8.4` (will be lowered if no 8.x features end up being required)
- ext-libxml, ext-mbstring, ext-simplexml, ext-gd (for raster image dimensions; SVG uses ext-simplexml)
- **No Node.js** — pure PHP

## Installation

```bash
composer require zergius-eggstream/amp-converter
```

## Usage

### Plain PHP

```php
use ZergiusEggstream\AmpConverter\AmpConverter;

$result = AmpConverter::createDefault()->convert($renderedHtml, $siteRoot);
file_put_contents($outputPath, $result->html);
foreach ($result->warnings as $w) {
    error_log("amp-converter: $w");
}
```

`$siteRoot` is the absolute path to the site's `public/` directory (so the converter can resolve relative image paths to read their dimensions).

### Symfony

The package ships an optional **Symfony bridge** that auto-wires the converter into your DI container.

```php
// config/bundles.php
return [
    // ...
    ZergiusEggstream\AmpConverter\Bridge\Symfony\AmpConverterBundle::class => ['all' => true],
];
```

```yaml
# config/packages/amp_converter.yaml  (optional)
amp_converter:
    redirects_tsv_path: '%kernel.project_dir%/data/cms/_data/redirects.tsv'
```

Now `AmpConverter`, `ImageSizeResolver`, and `AmpDomainResolver` (if configured) are autowired:

```php
public function __construct(
    private readonly AmpConverter $ampConverter,
) {}
```

The bridge requires `symfony/http-kernel` and `symfony/dependency-injection`, listed in `composer.json` `suggest`. Without them the bridge classes simply aren't autoloaded — the core library still works.

## Architecture

The converter is a **pipeline of transformers**. Each transformer implements `Transformer::apply(string $html, Context $ctx): string` and is responsible for one feature area (img conversion, iframe conversion, burger-menu detection, FAQ-to-accordion, defensive source fixes, etc.).

```
input HTML
    │
    ▼
┌─────────────────────────┐
│ HtmlSkeleton            │  <html ⚡>, charset, viewport
├─────────────────────────┤
│ ScriptStripping         │  remove inline JS, on*= handlers
├─────────────────────────┤
│ CssProcessing           │  font @import → link, !important strip
├─────────────────────────┤
│ ImgToAmpImg             │  layout decision (intrinsic/responsive/fill/fixed)
├─────────────────────────┤
│ IframeConversion        │  youtube → amp-youtube, others → amp-iframe
├─────────────────────────┤
│ FormConversion          │  form → div, submit → amp-bind
├─────────────────────────┤
│ DefensiveSourceFixes    │  hts:// typo, dup class, broken tags
├─────────────────────────┤
│ BurgerToAmpBind         │  3-tier detection, CSS-pair guard
├─────────────────────────┤
│ FaqToAccordion          │  schema.org, dl/dt/dd, sibling Q+A
├─────────────────────────┤
│ AutoContrastVars        │  resolve :auto color vars via YIQ luma
├─────────────────────────┤
│ PurgeCss                │  shrink <style amp-custom> below 75KB
├─────────────────────────┤
│ AmpRuntimeInjection     │  v0.js, boilerplate, custom-element scripts
└─────────────────────────┘
    │
    ▼
ConversionResult { html, usedComponents, warnings }
```

Replace the default pipeline by passing your own transformer list:

```php
new AmpConverter([
    new HtmlSkeleton(),
    new MyCustomTransformer(),
    // ...
]);
```

## Error handling

Strict but graceful: when the converter cannot transform a fragment (unparseable `<img>` tag, malformed CSS block, missing image dimensions), it **removes the fragment and records a warning** rather than throwing. The whole page still converts. Build pipelines decide what to do with warnings (log / fail / ignore).

`InvalidArgumentException` is reserved for programmer errors (non-existent `$siteRoot`, unreadable fixtures).

## Testing

- `tests/Unit/Transformer/*Test.php` — per-rule unit tests with synthetic fixtures from `tests/fixtures/unit/`.
- `tests/Regression/CorpusByteEqualityTest.php` — byte-equality against `tests/fixtures/corpus/<site>/expected.html` (real-world sites).

```bash
composer test
composer phpstan
```

Baselines in `tests/fixtures/corpus/` are committed to the repo so CI does not need Node. To regenerate them locally (during the port, while parity with the Node reference is the goal):

```bash
php bin/regen-fixtures.php
```

The long-term goal is full parity, after which the baseline becomes "previous PHP-converter output" — internal regression — and the Node dependency disappears entirely.

## License

MIT.
