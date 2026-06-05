# Port status

Tracking parity with the Node reference (`convert-rendered-to-amp.js`, v0.12, ~1800 LOC) shipped with [amp-seo-sites](https://github.com/zergius-eggstream/amp-seo-sites).

| Stage | Module | Status |
|---|---|---|
| 1 | Skeleton (composer, phpunit, phpstan, CI) | ✅ |
| 2 | Foundation (`Context`, `ConversionResult`, `Transformer`, `AmpConverter`, `DefaultPipeline`, `SnippetMasker`) | ✅ |
| 3 | `ImageSize/ImageSizeResolver` (getimagesize + SVG XML) | ✅ |
| 4 | `Transformer/CssProcessing` + `Transformer/FontImportInjection` (font @import → link, !important, @charset, vendor-media, broken --vars) | ✅ |
| 5 | `Transformer/ImgToAmpImg` (layout decision: fixed/intrinsic/responsive/fill) | ✅ |
| 6 | `Transformer/IframeConversion` (youtube → amp-youtube; other → amp-iframe; canvas → comment) | ✅ |
| 7 | `Transformer/FormConversion` (form → div, submit → amp-bind tap-action) | ✅ |
| 8 | `Transformer/DefensiveSourceFixes` (script strip, on*=, aria-roledescription, URL typos, broken `<hN>`, dup doctype/meta/html/head/body, head↔body cross-contamination, table border, rel/class dedupe, alt/loading on non-media, preload, oversized inline style) | ✅ |
| 9 | `Transformer/BurgerToAmpBind` (3-tier detection L1+L2+L3, CSS-pair guard with 4 hidden + 5 shown patterns, applyBurgerBinding) | ✅ |
| 10 | `Transformer/FaqToAccordion` (two-pass detection, sibling Q+A) | ⏳ |
| 11 | `Transformer/AutoContrastVars` (YIQ luma resolve) | ⏳ |
| 12 | `Transformer/PurgeCss` + `Transformer/AmpRuntimeInjection` | ⏳ |
| 13 | Orchestration smoke tests + corpus byte-equality regression | ⏳ |

Legend: ✅ done · 🚧 in progress · ⏳ not started

Reference spec lives in `dist/amp-converter-v0.12/doc/rendered-to-amp-algorithm.md` of the [amp-seo-sites repo](https://github.com/zergius-eggstream/amp-seo-sites/tree/main/dist/amp-converter-v0.12).
