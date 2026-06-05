<?php

declare(strict_types=1);

namespace AmpConverter\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer;

/**
 * Resolves CSS custom-property values literally set to `auto` (a
 * placeholder some WP themes use, then have a runtime JS like
 * `autoTextColor` flip to `#fff` / `#000` based on background
 * brightness). The runtime JS gets stripped by DefensiveSourceFixes,
 * which would leave `color: auto` and `background: auto` invalid —
 * AMP validator rejects them and the text loses its colour.
 *
 * Port of resolveAutoContrastVars + lumaPickBW from
 * tools/convert-rendered-to-amp.js (~lines 286-351).
 *
 * Strategy (both happen inside <style amp-custom>):
 *
 *   B (primary): pick body/html/:root background, extract a hex
 *      (direct or one indirection through `var(--X)`), compute YIQ
 *      luma → either `#000000` or `#ffffff`, and substitute that into
 *      every `--X: auto` declaration that is referenced from a colour
 *      context (color/background/border/outline/fill/stroke).
 *   A (fallback when B can't find a background): drop the offending
 *      `<color-prop>: ... var(--X) ...;` declarations entirely so
 *      the validator accepts the stylesheet (text gets browser default
 *      colour, which is better than invalid CSS).
 *
 * Custom props used in non-colour contexts (e.g. `width: var(--X)`)
 * are intentionally left alone — `auto` is valid there.
 */
final class AutoContrastVars implements Transformer
{
    private const COLOR_PROPS = '(?:color|background|background-color|border|border-color|outline|outline-color|fill|stroke)';

    public function apply(string $html, Context $ctx): string
    {
        return (string) preg_replace_callback(
            '#<style amp-custom>([\s\S]*?)</style>#i',
            function (array $m): string {
                return '<style amp-custom>' . $this->resolveInCss($m[1]) . '</style>';
            },
            $html,
            1,
        );
    }

    private function resolveInCss(string $css): string
    {
        $autoVars = $this->findAutoVars($css);
        if ($autoVars === []) {
            return $css;
        }

        $colorAutoVars = array_values(array_filter(
            $autoVars,
            fn (string $v): bool => $this->isReferencedInColorContext($v, $css),
        ));
        if ($colorAutoVars === []) {
            return $css;
        }

        $pageColor = $this->extractPageBackgroundColor($css);
        $contrast = $pageColor !== null ? $this->lumaPickBW($pageColor) : null;

        if ($contrast !== null) {
            // B: substitute `--X: auto` → contrast hex.
            foreach ($colorAutoVars as $v) {
                $esc = str_replace('-', '\\-', $v);
                $css = (string) preg_replace(
                    '/(' . $esc . '\s*:\s*)auto(\s*[;}])/i',
                    '$1' . $contrast . '$2',
                    $css,
                );
            }

            return $css;
        }

        // A: fallback. Strip invalid color-context decls that reference an
        // unresolved auto-var.
        foreach ($colorAutoVars as $v) {
            $esc = str_replace('-', '\\-', $v);
            $css = (string) preg_replace(
                '/' . self::COLOR_PROPS . '\s*:[^;{}]*var\(\s*' . $esc . '\b[^;{}]*;?/i',
                '',
                $css,
            );
        }

        return $css;
    }

    /**
     * @return list<string>
     */
    private function findAutoVars(string $css): array
    {
        if (preg_match_all('/(--[\w-]+)\s*:\s*auto\b\s*(?=[;}])/i', $css, $m) === 0) {
            return [];
        }
        // Preserve first-occurrence order, dedupe.
        $seen = [];
        foreach ($m[1] as $name) {
            if (!isset($seen[$name])) {
                $seen[$name] = true;
            }
        }

        return array_keys($seen);
    }

    private function isReferencedInColorContext(string $varName, string $css): bool
    {
        $esc = str_replace('-', '\\-', $varName);

        return preg_match(
            '/' . self::COLOR_PROPS . '\s*:[^;{}]*var\(\s*' . $esc . '\b/i',
            $css,
        ) === 1;
    }

    /**
     * Look at body, html, :root in that order. First match wins. Returns
     * a hex (3 or 6 chars, with leading `#`) or null.
     */
    private function extractPageBackgroundColor(string $css): ?string
    {
        foreach (['body', 'html', ':root'] as $sel) {
            $selEsc = str_replace(':', '\\:', $sel);
            if (preg_match('/(?:^|[,}{])\s*' . $selEsc . '\b[^{}]*\{([^}]*)\}/i', $css, $rm) !== 1) {
                continue;
            }
            if (preg_match('/\bbackground(?:-color)?\s*:\s*([^;}]+)/i', $rm[1], $bg) !== 1) {
                continue;
            }
            if (preg_match('/#[0-9a-fA-F]{3,6}\b/', $bg[1], $hex) === 1) {
                return $hex[0];
            }
            // One indirection through var(--X).
            if (preg_match('/var\(\s*(--[\w-]+)/', $bg[1], $vm) === 1) {
                $esc = str_replace('-', '\\-', $vm[1]);
                if (preg_match('/' . $esc . '\s*:\s*(#[0-9a-fA-F]{3,6})\b/', $css, $def) === 1) {
                    return $def[1];
                }
            }
        }

        return null;
    }

    /**
     * YIQ luma. > 128 = light background → use black; else use white.
     * Accepts 3- or 6-digit hex with `#`.
     */
    private function lumaPickBW(string $hex): ?string
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        if (preg_match('/^[0-9a-fA-F]{6}$/', $h) !== 1) {
            return null;
        }
        $r = (int) hexdec(substr($h, 0, 2));
        $g = (int) hexdec(substr($h, 2, 2));
        $b = (int) hexdec(substr($h, 4, 2));

        return ($r * 299 + $g * 587 + $b * 114) / 1000 > 128 ? '#000000' : '#ffffff';
    }
}
