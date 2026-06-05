<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer\BurgerToAmpBind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BurgerToAmpBindTest extends TestCase
{
    /**
     * @return array{html: string, ctx: Context}
     */
    private function apply(string $html): array
    {
        $ctx = new Context('/tmp/site');
        $out = (new BurgerToAmpBind())->apply($html, $ctx);

        return ['html' => $out, 'ctx' => $ctx];
    }

    private function wrapWithCss(string $css, string $bodyMarkup): string
    {
        return '<style amp-custom>' . $css . '</style>' . $bodyMarkup;
    }

    // === L1: trigger with burger class + aria-controls ===

    #[Test]
    public function l1AriaControlsToggleEmitsAmpBindAndAriaExpanded(): void
    {
        $css = '.nav-menu{display:none}.nav-menu.is-open{display:flex}';
        $html = $this->wrapWithCss(
            $css,
            '<button class="burger" aria-controls="nm">≡</button>'
            . '<nav id="nm" class="nav-menu"><a href="/">A</a></nav>',
        );
        $r = $this->apply($html);
        self::assertSame(['amp-bind' => true], $r['ctx']->usedComponents);
        self::assertStringContainsString('on="tap:AMP.setState({nav_nm:!nav_nm})"', $r['html']);
        self::assertStringContainsString('[aria-expanded]="nav_nm?\'true\':\'false\'"', $r['html']);
        self::assertStringContainsString('[class]="nav_nm?\'nav-menu is-open\':\'nav-menu\'"', $r['html']);
    }

    #[Test]
    public function l1ButtonTriggerDoesNotGainRoleOrTabindex(): void
    {
        $css = '.nav-menu{display:none}.nav-menu.is-open{display:flex}';
        $html = $this->wrapWithCss(
            $css,
            '<button class="burger" aria-controls="nm">≡</button>'
            . '<nav id="nm" class="nav-menu"></nav>',
        );
        $r = $this->apply($html);
        self::assertStringNotContainsString('role="button"', $r['html']);
        self::assertStringNotContainsString('tabindex="0"', $r['html']);
    }

    #[Test]
    public function l1DivTriggerGainsRoleAndTabindex(): void
    {
        $css = '.nav-menu{display:none}.nav-menu.is-open{display:flex}';
        $html = $this->wrapWithCss(
            $css,
            '<div class="burger" aria-controls="nm">≡</div>'
            . '<nav id="nm" class="nav-menu"></nav>',
        );
        $r = $this->apply($html);
        self::assertStringContainsString('role="button"', $r['html']);
        self::assertStringContainsString('tabindex="0"', $r['html']);
    }

    #[Test]
    public function l1AnchorTriggerHrefIsStripped(): void
    {
        $css = '.nav-menu{display:none}.nav-menu.is-open{display:flex}';
        $html = $this->wrapWithCss(
            $css,
            '<a class="burger" href="#" aria-controls="nm">≡</a>'
            . '<nav id="nm" class="nav-menu"></nav>',
        );
        $r = $this->apply($html);
        self::assertStringNotContainsString('href=', $r['html']);
        self::assertStringContainsString('role="button"', $r['html']);
    }

    // === L2: trigger with burger class, no aria-controls ===

    #[Test]
    public function l2ForwardNavGetsBound(): void
    {
        $css = '.main-nav{display:none}.main-nav.active{display:block}';
        $html = $this->wrapWithCss(
            $css,
            '<button class="nav-toggle">≡</button>'
            . '<nav class="main-nav"><a href="/">A</a></nav>',
        );
        $r = $this->apply($html);
        self::assertSame(['amp-bind' => true], $r['ctx']->usedComponents);
        self::assertStringContainsString('on="tap:AMP.setState', $r['html']);
        // No id on the nav — must be auto-generated.
        self::assertStringContainsString('id="amp-mnav-1"', $r['html']);
    }

    #[Test]
    public function l2BackwardNavSearchAlsoWorks(): void
    {
        // thaimag.kz pattern: nav in markup BEFORE the trigger.
        $css = '.main-nav{display:none}.main-nav.open{display:flex}';
        $html = $this->wrapWithCss(
            $css,
            '<nav class="main-nav"><a href="/">A</a></nav>'
            . '<header><button class="menu-toggle">≡</button></header>',
        );
        $r = $this->apply($html);
        self::assertStringContainsString('on="tap:AMP.setState', $r['html']);
        self::assertSame(['amp-bind' => true], $r['ctx']->usedComponents);
    }

    // === Hidden pattern variants ===

    #[Test]
    public function offCanvasNegativeLeftIsDetectedAsHidden(): void
    {
        // axoft.kz pattern.
        $css = '.side-nav{left:-100%;position:fixed}.side-nav.open{left:0}';
        $html = $this->wrapWithCss(
            $css,
            '<button class="burger" aria-controls="sn">≡</button><nav id="sn" class="side-nav"></nav>',
        );
        $r = $this->apply($html);
        self::assertStringContainsString('on="tap:AMP.setState', $r['html']);
    }

    #[Test]
    public function transformTranslateNegativeIsDetectedAsHidden(): void
    {
        $css = '.drawer{transform:translateX(-100%)}.drawer.open{transform:translateX(0)}';
        $html = $this->wrapWithCss(
            $css,
            '<button class="hamburger" aria-controls="dr">≡</button><nav id="dr" class="drawer"></nav>',
        );
        $r = $this->apply($html);
        self::assertStringContainsString('on="tap:AMP.setState', $r['html']);
    }

    #[Test]
    public function maxHeightZeroIsDetectedAsHidden(): void
    {
        $css = '.mobile-nav{max-height:0;overflow:hidden}.mobile-nav.is-open{max-height:600px}';
        $html = $this->wrapWithCss(
            $css,
            '<button class="menu-toggle" aria-controls="mn">≡</button><ul id="mn" class="mobile-nav"></ul>',
        );
        $r = $this->apply($html);
        self::assertStringContainsString('on="tap:AMP.setState', $r['html']);
    }

    // === Detection guards ===

    #[Test]
    public function plainHiddenNavWithoutModifierIsNotBound(): void
    {
        // No `.cls.MOD { ... }` rule that reverses → no toggle binding.
        $css = '.nav-menu{display:none}';
        $html = $this->wrapWithCss(
            $css,
            '<button class="burger">≡</button><nav class="nav-menu"></nav>',
        );
        $r = $this->apply($html);
        self::assertStringNotContainsString('on="tap:AMP.setState', $r['html']);
        self::assertSame([], $r['ctx']->usedComponents);
    }

    #[Test]
    public function visibleNavWithoutHiddenBaseIsNotBound(): void
    {
        // No hidden base CSS → no detection.
        $css = '.nav-menu{display:flex}.nav-menu.open{background:red}';
        $html = $this->wrapWithCss(
            $css,
            '<button class="burger">≡</button><nav class="nav-menu"></nav>',
        );
        $r = $this->apply($html);
        self::assertStringNotContainsString('on="tap:AMP.setState', $r['html']);
    }

    #[Test]
    public function noTriggerOrNavReturnsHtmlUnchanged(): void
    {
        $html = '<p>plain markup, no menu</p>';
        $r = $this->apply($html);
        self::assertSame($html, $r['html']);
        self::assertSame([], $r['ctx']->usedComponents);
    }

    // === L3: nav-driven, hamburger spans ===

    #[Test]
    public function l3HamburgerSpanPatternFindsTrigger(): void
    {
        // volnacasinokz pattern: trigger has no recognised class, BUT it
        // contains 3 empty <span> children before the collapsible nav.
        $css = '.mobile-nav{display:none}.mobile-nav.show{display:block}';
        $html = $this->wrapWithCss(
            $css,
            '<div class="some-random-class"><span></span><span></span><span></span></div>'
            . '<nav class="mobile-nav"><a href="/">A</a></nav>',
        );
        $r = $this->apply($html);
        self::assertSame(['amp-bind' => true], $r['ctx']->usedComponents);
        self::assertStringContainsString('on="tap:AMP.setState', $r['html']);
    }

    #[Test]
    public function l3NavBeforeTriggerWorks(): void
    {
        // Bidirectional: trigger AFTER the nav (volnacasinokz variant).
        $css = '.mobile-nav{display:none}.mobile-nav.show{display:block}';
        $html = $this->wrapWithCss(
            $css,
            '<nav class="mobile-nav"></nav>'
            . '<div class="ham"><span></span><span></span><span></span></div>',
        );
        $r = $this->apply($html);
        self::assertSame(['amp-bind' => true], $r['ctx']->usedComponents);
    }

    // === Auto-id generation ===

    #[Test]
    public function autoIdIsAddedToTargetWithoutId(): void
    {
        $css = '.main-nav{display:none}.main-nav.open{display:block}';
        $html = $this->wrapWithCss(
            $css,
            '<button class="burger">≡</button><nav class="main-nav"></nav>',
        );
        $r = $this->apply($html);
        self::assertStringContainsString('id="amp-mnav-1"', $r['html']);
        self::assertStringContainsString('nav_ampmnav1', $r['html']);
    }

    #[Test]
    public function existingTargetIdIsUsedAsIs(): void
    {
        $css = '.main-nav{display:none}.main-nav.open{display:block}';
        $html = $this->wrapWithCss(
            $css,
            '<button class="burger" aria-controls="main">≡</button>'
            . '<nav id="main" class="main-nav"></nav>',
        );
        $r = $this->apply($html);
        self::assertStringContainsString('nav_main', $r['html']);
        self::assertStringNotContainsString('amp-mnav-', $r['html']);
    }

    // === Counter resets per call ===

    #[Test]
    public function counterResetsBetweenApplyCalls(): void
    {
        $css = '.main-nav{display:none}.main-nav.open{display:block}';
        $body = '<button class="burger">≡</button><nav class="main-nav"></nav>';
        $transformer = new BurgerToAmpBind();
        $ctx1 = new Context('/tmp/site');
        $first = $transformer->apply($this->wrapWithCss($css, $body), $ctx1);
        $ctx2 = new Context('/tmp/site');
        $second = $transformer->apply($this->wrapWithCss($css, $body), $ctx2);
        self::assertStringContainsString('amp-mnav-1', $first);
        self::assertStringContainsString('amp-mnav-1', $second);
        self::assertStringNotContainsString('amp-mnav-2', $second);
    }
}
