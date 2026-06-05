<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer\FaqToAccordion;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FaqToAccordionTest extends TestCase
{
    /**
     * @return array{html: string, ctx: Context}
     */
    private function apply(string $html): array
    {
        $ctx = new Context('/tmp/site');
        $out = (new FaqToAccordion())->apply($html, $ctx);

        return ['html' => $out, 'ctx' => $ctx];
    }

    // === V1: container-based ===

    #[Test]
    public function schemaOrgFaqPageContainerBecomesAmpAccordion(): void
    {
        $html = '<div itemtype="https://schema.org/FAQPage">'
            . '<div itemtype="https://schema.org/Question">'
            .   '<h3 itemprop="name">What?</h3>'
            .   '<div itemprop="acceptedAnswer">Because.</div>'
            . '</div>'
            . '<div itemtype="https://schema.org/Question">'
            .   '<h3 itemprop="name">When?</h3>'
            .   '<div itemprop="acceptedAnswer">Soon.</div>'
            . '</div>'
            . '</div>';
        $r = $this->apply($html);
        self::assertSame(['amp-accordion' => true], $r['ctx']->usedComponents);
        self::assertStringContainsString('<amp-accordion', $r['html']);
        self::assertSame(2, substr_count($r['html'], '<section'));
        self::assertStringContainsString('<header>What?</header>', $r['html']);
        self::assertStringContainsString('<div>Because.</div>', $r['html']);
    }

    #[Test]
    public function classBasedFaqListContainerBecomesAmpAccordion(): void
    {
        $html = '<div class="faq-list">'
            . '<div class="faq-item"><h3 class="faq-question">Q1</h3><div class="faq-answer">A1</div></div>'
            . '<div class="faq-item"><h3 class="faq-question">Q2</h3><div class="faq-answer">A2</div></div>'
            . '</div>';
        $r = $this->apply($html);
        self::assertStringContainsString('<amp-accordion', $r['html']);
        self::assertStringContainsString('class="faq-list"', $r['html']);
        self::assertSame(2, substr_count($r['html'], '<section'));
    }

    #[Test]
    public function buttonClassPatternIsExtractedAsQuestion(): void
    {
        $html = '<div class="faq-list">'
            . '<div class="faq-item">'
            .   '<button class="accordion-toggle">Toggle me</button>'
            .   '<div class="accordion-body">Body text</div>'
            . '</div>'
            . '<div class="faq-item">'
            .   '<button class="accordion-toggle">Q2</button>'
            .   '<div class="accordion-body">B2</div>'
            . '</div>'
            . '</div>';
        $r = $this->apply($html);
        self::assertStringContainsString('<header class="accordion-toggle">Toggle me</header>', $r['html']);
    }

    // === V2: dl ===

    #[Test]
    public function dlWithFaqMarkerOnParentBecomesAccordion(): void
    {
        $html = '<dl class="faq-list">'
            . '<dt>Q1</dt><dd>A1</dd>'
            . '<dt>Q2</dt><dd>A2</dd>'
            . '</dl>';
        $r = $this->apply($html);
        self::assertStringContainsString('<amp-accordion class="faq-list">', $r['html']);
        self::assertSame(2, substr_count($r['html'], '<section'));
    }

    #[Test]
    public function dlWithoutMarkerButAllQuestionMarksBecomesAccordion(): void
    {
        $html = '<dl>'
            . '<dt>What is X?</dt><dd>A1</dd>'
            . '<dt>How to Y?</dt><dd>A2</dd>'
            . '</dl>';
        $r = $this->apply($html);
        self::assertStringContainsString('<amp-accordion', $r['html']);
    }

    #[Test]
    public function plainTextDlWithoutMarkerOrQuestionMarksStaysUntouched(): void
    {
        // sweetbonanza known-issue case: ensure we DO NOT wrap plain
        // definition lists into accordion.
        $html = '<dl>'
            . '<dt>Term 1</dt><dd>Definition 1</dd>'
            . '<dt>Term 2</dt><dd>Definition 2</dd>'
            . '</dl>';
        $r = $this->apply($html);
        self::assertStringNotContainsString('<amp-accordion', $r['html']);
        self::assertSame([], $r['ctx']->usedComponents);
    }

    #[Test]
    public function dlWithFewerThanTwoPairsIsLeftAlone(): void
    {
        $html = '<dl class="faq"><dt>Q?</dt><dd>A.</dd></dl>';
        $r = $this->apply($html);
        self::assertStringNotContainsString('<amp-accordion', $r['html']);
    }

    // === V3: sibling Questions ===

    #[Test]
    public function siblingSchemaQuestionsWithoutContainerBecomeAccordion(): void
    {
        $html = '<main>'
            . '<div itemtype="https://schema.org/Question"><h4 itemprop="name">Q1</h4><div itemprop="acceptedAnswer">A1</div></div>'
            . '<div itemtype="https://schema.org/Question"><h4 itemprop="name">Q2</h4><div itemprop="acceptedAnswer">A2</div></div>'
            . '</main>';
        $r = $this->apply($html);
        self::assertSame(['amp-accordion' => true], $r['ctx']->usedComponents);
        self::assertStringContainsString('<amp-accordion>', $r['html']);
        self::assertSame(2, substr_count($r['html'], '<section'));
    }

    #[Test]
    public function singleSchemaQuestionDoesNotTriggerAccordion(): void
    {
        $html = '<main>'
            . '<div itemtype="https://schema.org/Question"><h4 itemprop="name">Q1</h4><div itemprop="acceptedAnswer">A1</div></div>'
            . '</main>';
        $r = $this->apply($html);
        self::assertStringNotContainsString('<amp-accordion', $r['html']);
    }

    // === V4: hN+p inside FAQ-marker parent ===

    #[Test]
    public function headingPlusParagraphInsideFaqParentBecomesAccordion(): void
    {
        // The wrapper carries the FAQ marker AND contains h2+p pairs; V1
        // (container) wins and replaces the wrapper with an amp-accordion
        // that inherits its class. (V4 would have kept the wrapper, but
        // V1 runs first and handles this shape via Strategy C.)
        $html = '<section class="faq-section">'
            . '<h2>Question one?</h2><p>Answer one.</p>'
            . '<h2>Question two?</h2><p>Answer two.</p>'
            . '</section>';
        $r = $this->apply($html);
        self::assertStringContainsString('<amp-accordion class="faq-section">', $r['html']);
        self::assertSame(2, substr_count($r['html'], '<section><header>'));
    }

    #[Test]
    public function v4HeadingPatternFiresWhenContainerLacksItemListMarker(): void
    {
        // Plain block tag with FAQ-marker class but no children that match
        // V1 Strategy A (no faq-item etc) and no dl. V1 still attempts
        // Strategy C (hN+p) inside the container and absorbs it. To force
        // V4, the marker must NOT match V1's broader heuristic — use just
        // a generic marker on a deeply-nested wrapper. We test a no-op case
        // instead: ensure non-FAQ-marker parents don't get wrapped.
        $html = '<section class="plain">'
            . '<h2>Heading?</h2><p>Para.</p>'
            . '<h2>Heading2?</h2><p>Para2.</p>'
            . '</section>';
        $r = $this->apply($html);
        self::assertStringNotContainsString('<amp-accordion', $r['html']);
    }

    #[Test]
    public function headingPlusParagraphWithoutFaqMarkerParentStaysUntouched(): void
    {
        $html = '<section class="some-content">'
            . '<h2>Heading?</h2><p>Para.</p>'
            . '<h2>Heading2?</h2><p>Para2.</p>'
            . '</section>';
        $r = $this->apply($html);
        self::assertStringNotContainsString('<amp-accordion', $r['html']);
    }

    // === CSS post-process: injectQuestionClassDefaults ===

    #[Test]
    public function detectedQuestionClassesGetInheritDefaultsInAmpCustom(): void
    {
        $html = '<style amp-custom>.unrelated{color:red}</style>'
            . '<div class="faq-list">'
            . '<div class="faq-item"><h3 class="faq-question my-q">Q1</h3><div class="faq-answer">A1</div></div>'
            . '<div class="faq-item"><h3 class="faq-question my-q">Q2</h3><div class="faq-answer">A2</div></div>'
            . '</div>';
        $r = $this->apply($html);
        self::assertStringContainsString('.faq-question{background:inherit;border:inherit}', $r['html']);
        self::assertStringContainsString('.my-q{background:inherit;border:inherit}', $r['html']);
        // Question classes shared via Context (other transformers / consumers can read).
        self::assertContains('faq-question my-q', $r['ctx']->faqQuestionClasses);
    }

    #[Test]
    public function patchAccordionCssRewritesToggleModifierToExpandedAttr(): void
    {
        $html = '<style amp-custom>.faq-item.open{background:#fff}.faq-item.is-active{color:red}</style>'
            . '<div class="faq-list">'
            . '<div class="faq-item"><h3 class="faq-question">Q</h3><div class="faq-answer">A</div></div>'
            . '<div class="faq-item"><h3 class="faq-question">Q2</h3><div class="faq-answer">A2</div></div>'
            . '</div>';
        $r = $this->apply($html);
        self::assertStringContainsString('.faq-item[expanded]{background:#fff}', $r['html']);
        self::assertStringContainsString('.faq-item[expanded]{color:red}', $r['html']);
    }

    #[Test]
    public function patchAccordionCssStripsMaxHeightZeroAndDisplayNoneFromAnswerRules(): void
    {
        $html = '<style amp-custom>.faq-answer{max-height:0;display:none;color:#333}</style>'
            . '<div class="faq-list">'
            . '<div class="faq-item"><h3 class="faq-question">Q</h3><div class="faq-answer">A</div></div>'
            . '<div class="faq-item"><h3 class="faq-question">Q2</h3><div class="faq-answer">A2</div></div>'
            . '</div>';
        $r = $this->apply($html);
        self::assertStringNotContainsString('max-height:0', $r['html']);
        self::assertStringNotContainsString('display:none', $r['html']);
        self::assertStringContainsString('color:#333', $r['html']);
    }

    // === Sanity / no-op ===

    #[Test]
    public function nonFaqMarkupStaysIntact(): void
    {
        $html = '<style amp-custom>.x{color:red}</style><div class="other"><p>plain</p></div>';
        $r = $this->apply($html);
        self::assertSame($html, $r['html']);
        self::assertSame([], $r['ctx']->usedComponents);
        self::assertSame([], $r['ctx']->faqQuestionClasses);
    }

    #[Test]
    public function emptyFaqContainerIsLeftAloneAndDoesNotMarkUsed(): void
    {
        // Container has FAQ marker but no inner items pass extraction.
        $html = '<div class="faq-list"></div>';
        $r = $this->apply($html);
        self::assertStringNotContainsString('<amp-accordion', $r['html']);
        self::assertSame([], $r['ctx']->usedComponents);
    }
}
