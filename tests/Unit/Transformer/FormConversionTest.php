<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer\FormConversion;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FormConversionTest extends TestCase
{
    /**
     * @return array{html: string, ctx: Context}
     */
    private function apply(string $html): array
    {
        $ctx = new Context('/tmp/site');
        $out = (new FormConversion())->apply($html, $ctx);

        return ['html' => $out, 'ctx' => $ctx];
    }

    #[Test]
    public function emptyFormBecomesDataWasFormDiv(): void
    {
        $r = $this->apply('<form></form>');
        self::assertSame('<div data-was-form="true"></div>', $r['html']);
        self::assertSame(['amp-bind' => true], $r['ctx']->usedComponents);
    }

    #[Test]
    public function classAttributeIsPreserved(): void
    {
        $r = $this->apply('<form class="hero-cta"></form>');
        self::assertStringContainsString('class="hero-cta"', $r['html']);
        self::assertStringStartsWith('<div', $r['html']);
    }

    #[Test]
    public function idAndDataAttrsArePreserved(): void
    {
        $r = $this->apply('<form id="reg" data-section="hero"></form>');
        self::assertStringContainsString('id="reg"', $r['html']);
        self::assertStringContainsString('data-section="hero"', $r['html']);
    }

    #[Test]
    public function stripsActionAttr(): void
    {
        $r = $this->apply('<form action="/submit" class="x"></form>');
        self::assertStringNotContainsString('action=', $r['html']);
        self::assertStringContainsString('class="x"', $r['html']);
    }

    #[Test]
    public function stripsMethodAttr(): void
    {
        $r = $this->apply('<form method="post" class="x"></form>');
        self::assertStringNotContainsString('method=', $r['html']);
    }

    #[Test]
    public function stripsNovalidateAttrWithOrWithoutValue(): void
    {
        $a = $this->apply('<form novalidate class="x"></form>');
        $b = $this->apply('<form novalidate="" class="x"></form>');
        self::assertStringNotContainsString('novalidate', $a['html']);
        self::assertStringNotContainsString('novalidate', $b['html']);
    }

    #[Test]
    public function stripsEnctype(): void
    {
        $r = $this->apply('<form enctype="multipart/form-data" class="x"></form>');
        self::assertStringNotContainsString('enctype=', $r['html']);
    }

    #[Test]
    public function stripsAutocomplete(): void
    {
        $r = $this->apply('<form autocomplete="off" class="x"></form>');
        self::assertStringNotContainsString('autocomplete=', $r['html']);
    }

    #[Test]
    public function buttonTypeSubmitGetsTapAction(): void
    {
        $r = $this->apply('<form><button type="submit">Send</button></form>');
        self::assertStringContainsString('on="tap:AMP.setState({form_sent:true})"', $r['html']);
        self::assertStringContainsString('>Send</button>', $r['html']);
    }

    #[Test]
    public function buttonTypeSubmitPreservesSurroundingAttrs(): void
    {
        $r = $this->apply('<form><button class="cta" type="submit" id="go">Go</button></form>');
        self::assertStringContainsString('class="cta"', $r['html']);
        self::assertStringContainsString('id="go"', $r['html']);
        self::assertStringContainsString('on="tap:AMP.setState({form_sent:true})"', $r['html']);
    }

    #[Test]
    public function inputTypeSubmitBecomesButton(): void
    {
        $r = $this->apply('<form><input type="submit" value="Send"></form>');
        self::assertStringContainsString('<button', $r['html']);
        self::assertStringContainsString('on="tap:AMP.setState({form_sent:true})"', $r['html']);
        self::assertStringContainsString('>Отправить</button>', $r['html']);
        self::assertStringNotContainsString('<input', $r['html']);
    }

    #[Test]
    public function selfClosingInputSubmitIsAlsoConverted(): void
    {
        $r = $this->apply('<form><input type="submit" /></form>');
        self::assertStringContainsString('<button', $r['html']);
        self::assertStringContainsString('on="tap:AMP.setState({form_sent:true})"', $r['html']);
    }

    #[Test]
    public function nonSubmitButtonStaysUntouched(): void
    {
        $r = $this->apply('<form><button type="button" class="cancel">Cancel</button></form>');
        self::assertStringNotContainsString('on="tap:', $r['html']);
        self::assertStringContainsString('type="button"', $r['html']);
    }

    #[Test]
    public function nonSubmitInputStaysUntouched(): void
    {
        $r = $this->apply('<form><input type="text" name="email"></form>');
        self::assertStringNotContainsString('<button', $r['html']);
        self::assertStringContainsString('<input type="text"', $r['html']);
    }

    #[Test]
    public function multipleSubmitButtonsAreAllConverted(): void
    {
        $r = $this->apply(
            '<form><button type="submit">A</button><button type="submit">B</button></form>',
        );
        self::assertSame(2, substr_count($r['html'], 'on="tap:AMP.setState({form_sent:true})"'));
    }

    #[Test]
    public function nonFormMarkupIsUnchanged(): void
    {
        $r = $this->apply('<div>plain</div>');
        self::assertSame('<div>plain</div>', $r['html']);
        self::assertSame([], $r['ctx']->usedComponents);
    }

    #[Test]
    public function bodyMarkupIsPreservedVerbatim(): void
    {
        $r = $this->apply(
            '<form action="/x" class="hero"><label>Name <input name="n"></label><button type="submit">Send</button></form>',
        );
        self::assertStringContainsString('<label>Name <input name="n"></label>', $r['html']);
        self::assertStringContainsString('class="hero"', $r['html']);
        self::assertStringNotContainsString('<form', $r['html']);
        self::assertStringNotContainsString('action=', $r['html']);
    }

    #[Test]
    public function multipleFormsAreEachConverted(): void
    {
        $r = $this->apply('<form id="a"></form><form id="b"></form>');
        self::assertSame(2, substr_count($r['html'], 'data-was-form="true"'));
    }
}
