<?php

declare(strict_types=1);

namespace AmpConverter\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer;

/**
 * Port of the <form> rewrite from tools/convert-rendered-to-amp.js
 * (~lines 1573-1593).
 *
 * We DON'T translate to amp-form: it would require a host-side server
 * endpoint that the build pipelines this package targets typically
 * don't have. Instead the form becomes a <div data-was-form="true"> so the
 * markup survives, and any submit control gets an amp-bind tap-action
 * that sets a global `form_sent` flag — the surrounding markup can
 * react to that with [class] / [hidden] bindings if needed.
 *
 * Submit translations (also private methods, each unit-testable):
 *   - <button type="submit"> → on="tap:AMP.setState({form_sent:true})"
 *   - <input  type="submit"> → <button on="..."> "Отправить"
 *
 * Stripped from the outer wrapping `<div>`:
 *   action, method, novalidate, enctype, autocomplete.
 * Preserved: id, class, data-*, role, aria-*, anything else.
 */
final class FormConversion implements Transformer
{
    public function apply(string $html, Context $ctx): string
    {
        return (string) preg_replace_callback(
            '#<form\b([^>]*)>([\s\S]*?)</form>#i',
            function (array $m) use ($ctx): string {
                $ctx->markComponentUsed('amp-bind');
                $body = $this->rewriteSubmitControls($m[2]);
                $cleanAttrs = $this->stripFormAttrs($m[1]);

                return '<div' . $cleanAttrs . ' data-was-form="true">' . $body . '</div>';
            },
            $html,
        );
    }

    private function rewriteSubmitControls(string $body): string
    {
        $body = (string) preg_replace_callback(
            '#<button\b([^>]*)\btype=["\']submit["\']([^>]*)>([\s\S]*?)</button>#i',
            static fn (array $m): string => '<button' . $m[1] . ' on="tap:AMP.setState({form_sent:true})"' . $m[2] . '>' . $m[3] . '</button>',
            $body,
        );

        return (string) preg_replace_callback(
            '#<input\b([^>]*)\btype=["\']submit["\']([^>]*?)\/?>#i',
            static fn (array $m): string => '<button' . $m[1] . ' on="tap:AMP.setState({form_sent:true})"' . $m[2] . '>Отправить</button>',
            $body,
        );
    }

    private function stripFormAttrs(string $attrs): string
    {
        return (string) preg_replace(
            [
                '/\s*action=["\'][^"\']*["\']/i',
                '/\s*method=["\'][^"\']*["\']/i',
                '/\s+novalidate(?:=["\'][^"\']*["\'])?/i',
                '/\s+enctype=["\'][^"\']*["\']/i',
                '/\s+autocomplete=["\'][^"\']*["\']/i',
            ],
            ['', '', '', '', ''],
            $attrs,
        );
    }
}
