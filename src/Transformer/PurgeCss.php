<?php

declare(strict_types=1);

namespace AmpConverter\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer;

/**
 * Drops CSS rules whose selectors don't match anything in the rendered
 * HTML. Only fires when the `<style amp-custom>` block exceeds
 * `THRESHOLD_BYTES` (60 KB by default, comfortably below AMP's 75 KB
 * limit). Below that, leaving the CSS as-is is safer than risking a
 * mis-prune.
 *
 * Port of purgeCss + selectorUsed + collectUsedTokens from
 * tools/convert-rendered-to-amp.js (~lines 358-472). The parser is a
 * simple state machine over the CSS string — not a full CSS parser,
 * but it handles 95% of selectors seen in the corpus.
 *
 * What's preserved unconditionally:
 *   - @font-face and @keyframes (referenced indirectly through
 *     animation / font-family).
 *   - @-vendor at-rules.
 *   - "Safe" selectors that don't reference HTML tokens: *, :root,
 *     ::placeholder, ::selection, ::before, ::after, scrollbar
 *     pseudo-elements, :focus / :hover / :active.
 *   - Selectors with no class / id / tag at all (e.g. attribute-only,
 *     pure pseudo-class chains) — assumed to be intentional.
 *   - @media and @supports → contents recursively purged.
 */
final class PurgeCss implements Transformer
{
    private const THRESHOLD_BYTES = 60 * 1024;

    private const ALWAYS_SAFE_SELECTORS = [
        '*' => true,
        ':root' => true,
        '::placeholder' => true,
        '::selection' => true,
        '::before' => true,
        '::after' => true,
        '::-webkit-scrollbar' => true,
        '::-webkit-scrollbar-track' => true,
        '::-webkit-scrollbar-thumb' => true,
        '::-moz-selection' => true,
        '::-webkit-input-placeholder' => true,
        ':focus' => true,
        ':hover' => true,
        ':active' => true,
    ];

    public function apply(string $html, Context $ctx): string
    {
        return (string) preg_replace_callback(
            '#<style amp-custom>([\s\S]*?)</style>#i',
            function (array $m) use ($html): string {
                $css = $m[1];
                if (strlen($css) <= self::THRESHOLD_BYTES) {
                    return $m[0];
                }
                $tokens = $this->collectUsedTokens($html);

                return '<style amp-custom>' . $this->purgeCssBlock($css, $tokens) . '</style>';
            },
            $html,
            1,
        );
    }

    /**
     * @return array{classes: array<string, true>, ids: array<string, true>, tags: array<string, true>}
     */
    private function collectUsedTokens(string $html): array
    {
        $classes = [];
        $ids = [];
        $tags = [];
        if (preg_match_all('/\sclass=["\']([^"\']+)["\']/i', $html, $cm) > 0) {
            foreach ($cm[1] as $val) {
                foreach (preg_split('/\s+/', $val) ?: [] as $c) {
                    if ($c !== '') {
                        $classes[$c] = true;
                    }
                }
            }
        }
        if (preg_match_all('/\sid=["\']([^"\']+)["\']/i', $html, $im) > 0) {
            foreach ($im[1] as $id) {
                $ids[trim($id)] = true;
            }
        }
        if (preg_match_all('/<([a-z][\w-]*)/i', $html, $tm) > 0) {
            foreach ($tm[1] as $tag) {
                $tags[strtolower($tag)] = true;
            }
        }

        return ['classes' => $classes, 'ids' => $ids, 'tags' => $tags];
    }

    /**
     * @param array{classes: array<string, true>, ids: array<string, true>, tags: array<string, true>} $tokens
     */
    private function purgeCssBlock(string $css, array $tokens): string
    {
        // Comments aren't nested → strip in one pass before parsing.
        $stripped = (string) preg_replace('#/\*[\s\S]*?\*/#', '', $css);
        $out = [];
        $i = 0;
        $n = strlen($stripped);

        while ($i < $n) {
            while ($i < $n && ctype_space($stripped[$i])) {
                $i++;
            }
            if ($i >= $n) {
                break;
            }
            if ($stripped[$i] === '@') {
                $i = $this->emitAtRule($stripped, $i, $tokens, $out);
            } else {
                $i = $this->emitRegularRule($stripped, $i, $tokens, $out);
            }
        }

        return implode("\n", $out);
    }

    /**
     * @param array{classes: array<string, true>, ids: array<string, true>, tags: array<string, true>} $tokens
     * @param list<string>                                                                              $out
     */
    private function emitAtRule(string $css, int $i, array $tokens, array &$out): int
    {
        $n = strlen($css);
        $j = $i;
        while ($j < $n && $css[$j] !== ';' && $css[$j] !== '{') {
            $j++;
        }
        if ($j >= $n) {
            return $n;
        }
        if ($css[$j] === ';') {
            $out[] = substr($css, $i, $j - $i + 1);

            return $j + 1;
        }
        // @-rule with body.
        $head = substr($css, $i, $j - $i + 1);
        $parts = preg_split('/[\s({]/', trim($head)) ?: [];
        $atName = strtolower($parts[0] ?? '');
        $depth = 1;
        $k = $j + 1;
        while ($k < $n && $depth > 0) {
            if ($css[$k] === '{') {
                $depth++;
            } elseif ($css[$k] === '}') {
                $depth--;
            }
            $k++;
        }
        $body = substr($css, $j + 1, $k - 1 - ($j + 1));

        if ($atName === '@font-face' || $atName === '@keyframes' || str_starts_with($atName, '@-')) {
            $out[] = substr($css, $i, $k - $i);
        } elseif ($atName === '@media' || $atName === '@supports') {
            $purgedBody = $this->purgeCssBlock($body, $tokens);
            if (trim($purgedBody) !== '') {
                $out[] = $head . $purgedBody . '}';
            }
        } else {
            $out[] = substr($css, $i, $k - $i);
        }

        return $k;
    }

    /**
     * @param array{classes: array<string, true>, ids: array<string, true>, tags: array<string, true>} $tokens
     * @param list<string>                                                                              $out
     */
    private function emitRegularRule(string $css, int $i, array $tokens, array &$out): int
    {
        $n = strlen($css);
        $j = $i;
        while ($j < $n && $css[$j] !== '{' && $css[$j] !== '}') {
            $j++;
        }
        if ($j >= $n || $css[$j] === '}') {
            return $j + 1;
        }
        $selector = trim(substr($css, $i, $j - $i));
        $depth = 1;
        $k = $j + 1;
        while ($k < $n && $depth > 0) {
            if ($css[$k] === '{') {
                $depth++;
            } elseif ($css[$k] === '}') {
                $depth--;
            }
            $k++;
        }
        if ($this->selectorUsed($selector, $tokens)) {
            $out[] = substr($css, $i, $k - $i);
        }

        return $k;
    }

    /**
     * @param array{classes: array<string, true>, ids: array<string, true>, tags: array<string, true>} $tokens
     */
    private function selectorUsed(string $selectorList, array $tokens): bool
    {
        foreach (explode(',', $selectorList) as $sel) {
            $sel = trim($sel);
            if ($this->oneSelectorUsed($sel, $tokens)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{classes: array<string, true>, ids: array<string, true>, tags: array<string, true>} $tokens
     */
    private function oneSelectorUsed(string $sel, array $tokens): bool
    {
        if ($sel === '') {
            return true;
        }
        if (isset(self::ALWAYS_SAFE_SELECTORS[$sel])) {
            return true;
        }
        // Strip pseudo-elements / pseudo-classes so we look at the structural tokens only.
        $stripped = (string) preg_replace('/::?[\w-]+(?:\([^)]*\))?/', '', $sel);

        preg_match_all('/\.[\w-]+/', $stripped, $cm);
        preg_match_all('/#[\w-]+/', $stripped, $im);
        $classes = $cm[0];
        $ids = $im[0];
        preg_match_all('/(?:^|[\s>+~])([a-z][\w-]*)/i', $stripped, $tm);
        /** @var list<string> $tagList */
        $tagList = array_map('strtolower', $tm[1]);

        foreach ($classes as $c) {
            if (isset($tokens['classes'][substr($c, 1)])) {
                return true;
            }
        }
        foreach ($ids as $id) {
            if (isset($tokens['ids'][substr($id, 1)])) {
                return true;
            }
        }
        foreach ($tagList as $t) {
            if (isset($tokens['tags'][$t])) {
                return true;
            }
        }
        // No class / id / tag at all → keep (e.g. pure attribute selector or
        // pseudo chain).
        return $classes === [] && $ids === [] && $tagList === [];
    }
}
