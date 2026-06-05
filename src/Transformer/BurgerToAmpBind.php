<?php

declare(strict_types=1);

namespace AmpConverter\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer;

/**
 * Hamburger / mobile-menu toggle → amp-bind tap-action.
 *
 * Port of `convertBurgerMenu`, `detectCollapsibleModifier`,
 * `findCollapsibleNavNear`, `applyBurgerBinding`, `resolveTargetForTrigger`,
 * `tryNavDrivenBurger` from tools/convert-rendered-to-amp.js (~lines 490-660).
 *
 * Three detection levels, tried in order:
 *
 *   L1: trigger element carries a known burger class AND aria-controls=ID
 *       → use the referenced element as target.
 *   L2: trigger element carries a known burger class but no aria-controls
 *       → find nearest collapsible <nav>/<ul> in either direction
 *       (3000 chars forward, 2500 chars backward).
 *   L3: nav-driven fallback. Find any collapsible nav, then reverse-find
 *       the trigger:
 *         3a: any tag with aria-controls=navId.
 *         3b: hamburger-content pattern (2-4 empty <span>'s in a row)
 *             in a 2000-char window around the nav.
 *
 * Collapsibility = a base `.cls { ... }` with a hidden signal AND a
 * `.cls.MODIFIER { ... }` that flips it back. Hidden signals: display:none,
 * off-screen positioning (left/right/top/bottom:-N), transform:translate
 * with a negative axis, max-height:0. Shown signals: display:flex|block|
 * grid, position 0, transform:translate(0) or none, max-height non-zero,
 * opacity:1. The pair guard prevents false-positives on plain hidden
 * containers that have no toggle modifier.
 *
 * No exceptions thrown. When nothing is detected, the HTML is returned
 * verbatim. Successful detection marks amp-bind as a used component on
 * Context and binds:
 *   - trigger: `on="tap:AMP.setState({nav_X:!nav_X})"`
 *              + `[aria-expanded]="nav_X?'true':'false'"`
 *              + role="button" + tabindex="0" for non-<button> triggers
 *              + drops href on <a> triggers (avoids jump-to-top)
 *   - target: `[class]="nav_X ? '<orig> <mod>' : '<orig>'"`
 */
final class BurgerToAmpBind implements Transformer
{
    private const TRIGGER_CLASSES = '(?:burger|hamburger|menu-toggle|nav-toggle|menu-btn|mobile-menu-btn|navbar-toggle|menu-icon|nav-trigger|nav-icon|menu-trigger)';

    private int $navCounter = 0;

    public function apply(string $html, Context $ctx): string
    {
        $this->navCounter = 0;
        $css = $this->extractAmpCustomCss($html);

        // L1 + L2: try class-based trigger first.
        $trigRe = '/<(button|a|div|span)\b([^>]*\bclass=["\'][^"\']*\b' . self::TRIGGER_CLASSES . '\b[^"\']*["\'][^>]*)>/i';
        if (preg_match($trigRe, $html, $tm, PREG_OFFSET_CAPTURE) === 1) {
            $trigFull = $tm[0][0];
            $trigTag = strtolower($tm[1][0]);
            $trigAttrs = $tm[2][0];
            $trigIdx = $tm[0][1];

            $target = $this->resolveTargetForTrigger($html, $css, $trigAttrs, $trigIdx);
            if ($target !== null) {
                $ctx->markComponentUsed('amp-bind');

                return $this->applyBurgerBinding(
                    $html,
                    $trigFull,
                    $trigTag,
                    $target['openTag'],
                    $target['cls'],
                    $target['id'],
                    $target['modifier'],
                );
            }
        }

        // L3: nav-driven fallback.
        return $this->tryNavDrivenBurger($html, $css, $ctx);
    }

    private function extractAmpCustomCss(string $html): string
    {
        if (preg_match('#<style amp-custom>([\s\S]*?)</style>#i', $html, $m) !== 1) {
            return '';
        }

        return $m[1];
    }

    /**
     * @return ?array{openTag: string, cls: string, id: string, modifier: string}
     */
    private function resolveTargetForTrigger(string $html, string $css, string $trigAttrs, int $trigIdx): ?array
    {
        // aria-controls=ID short-circuit.
        if (preg_match('/\baria-controls=["\']([^"\']+)["\']/', $trigAttrs, $ac) === 1) {
            $safeId = (string) preg_replace('/[^\w-]/', '', $ac[1]);
            if ($safeId !== ''
                && preg_match('/<(\w+)\b([^>]*\bid=["\']' . preg_quote($safeId, '/') . '["\'][^>]*)>/i', $html, $tm) === 1
            ) {
                $cls = $this->firstAttrValue($tm[2], 'class') ?? '';
                $firstCls = $this->firstToken($cls);
                $mod = $this->detectCollapsibleModifier($firstCls, $css);
                if ($mod !== null) {
                    return ['openTag' => $tm[0], 'cls' => $cls, 'id' => $safeId, 'modifier' => $mod];
                }
            }
        }

        // Structural fallback.
        return $this->findCollapsibleNavNear($html, $trigIdx, $css);
    }

    /**
     * @return ?array{openTag: string, cls: string, id: string, modifier: string}
     */
    private function findCollapsibleNavNear(string $html, int $trigIdx, string $css): ?array
    {
        $navRe = '/<(nav|ul)\b([^>]*)>/i';

        // Forward window (3000 chars after the trigger).
        $forward = substr($html, $trigIdx, 3000);
        if (preg_match_all($navRe, $forward, $fm, PREG_SET_ORDER) > 0) {
            foreach ($fm as $m) {
                $cls = $this->firstAttrValue($m[2], 'class') ?? '';
                $id = $this->firstAttrValue($m[2], 'id') ?? '';
                $mod = $this->detectCollapsibleModifier($this->firstToken($cls), $css);
                if ($mod !== null) {
                    return ['openTag' => $m[0], 'cls' => $cls, 'id' => $id, 'modifier' => $mod];
                }
            }
        }

        // Backward window (2500 chars before the trigger). Closest match wins
        // — keep iterating and overwrite to land on the last one in the slice
        // (which is the nearest preceding the trigger position).
        $backwardStart = max(0, $trigIdx - 2500);
        $backward = substr($html, $backwardStart, $trigIdx - $backwardStart);
        $last = null;
        if (preg_match_all($navRe, $backward, $bm, PREG_SET_ORDER) > 0) {
            foreach ($bm as $m) {
                $cls = $this->firstAttrValue($m[2], 'class') ?? '';
                $id = $this->firstAttrValue($m[2], 'id') ?? '';
                $mod = $this->detectCollapsibleModifier($this->firstToken($cls), $css);
                if ($mod !== null) {
                    $last = ['openTag' => $m[0], 'cls' => $cls, 'id' => $id, 'modifier' => $mod];
                }
            }
        }

        return $last;
    }

    /**
     * Five hidden patterns:
     *   - display:none
     *   - off-canvas: left/right/top/bottom:-N
     *   - transform:translate with negative axis
     *   - max-height:0
     * (opacity:0 is intentionally NOT a hidden signal — many themes use
     * it for hover transitions on visible elements; the JS source omits
     * it from `isHidden` and so do we.)
     *
     * Five shown patterns:
     *   - display:flex|block|grid|inline-flex|inline-block
     *   - position 0
     *   - transform:translate(0) or none
     *   - max-height non-zero numeric
     *   - opacity:1
     */
    private function detectCollapsibleModifier(string $cls, string $css): ?string
    {
        if ($cls === '') {
            return null;
        }
        $esc = str_replace('-', '\\-', $cls);

        // (1) base `.cls { ... }` collapsed?
        $baseRe = '/(?:^|[,}{])\s*\.' . $esc . '(?![\w.-])[^{}]*\{([^}]*)\}/i';
        $collapsed = false;
        if (preg_match_all($baseRe, $css, $bm) > 0) {
            foreach ($bm[1] as $body) {
                if ($this->isHidden($body)) {
                    $collapsed = true;
                    break;
                }
            }
        }
        if (!$collapsed) {
            return null;
        }

        // (2) `.cls.MOD { ... }` shown? Take first matching modifier.
        $modRe = '/\.' . $esc . '\.([\w-]+)(?![\w-])[^{}]*\{([^}]*)\}/i';
        if (preg_match_all($modRe, $css, $mm) > 0) {
            foreach ($mm[2] as $i => $body) {
                if ($this->isShown($body)) {
                    return $mm[1][$i];
                }
            }
        }

        return null;
    }

    private function isHidden(string $body): bool
    {
        return preg_match('/display\s*:\s*none/i', $body) === 1
            || preg_match('/(?:left|right|top|bottom)\s*:\s*-\s*\d/i', $body) === 1
            || preg_match('/transform\s*:[^;}]*translate[XY3d]*\(\s*-?\s*(?:100%|[1-9]\d*(?:px|%|vw|vh|rem|em))/i', $body) === 1
            || preg_match('/max-height\s*:\s*0(?:px|%)?\s*(?:[;}!]|$)/i', $body) === 1;
    }

    private function isShown(string $body): bool
    {
        return preg_match('/display\s*:\s*(?:flex|block|grid|inline-flex|inline-block)/i', $body) === 1
            || preg_match('/(?:left|right|top|bottom)\s*:\s*0/i', $body) === 1
            || preg_match('/transform\s*:[^;}]*(?:translate[XY3d]*\(\s*0|none)/i', $body) === 1
            || preg_match('/max-height\s*:\s*(?!0(?:px|%)?\s*[;}!])[\d.]+/i', $body) === 1
            || preg_match('/opacity\s*:\s*1\b/i', $body) === 1;
    }

    /**
     * 3a: any tag with aria-controls=navId.
     * 3b: hamburger content pattern (2-4 empty <span>'s) near the nav.
     */
    private function tryNavDrivenBurger(string $html, string $css, Context $ctx): string
    {
        $navRe = '/<(nav|ul|div)\b([^>]*)>/i';
        if (preg_match_all($navRe, $html, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
            return $html;
        }

        foreach ($matches as $m) {
            $cls = $this->firstAttrValue($m[2][0], 'class') ?? '';
            $id = $this->firstAttrValue($m[2][0], 'id') ?? '';
            $mod = $this->detectCollapsibleModifier($this->firstToken($cls), $css);
            if ($mod === null) {
                continue;
            }
            $targetOpenTag = $m[0][0];
            $navIdx = $m[0][1];

            $trigFull = null;
            $trigTag = null;

            // 3a.
            if ($id !== '') {
                $safeId = (string) preg_replace('/[^\w-]/', '', $id);
                if ($safeId !== ''
                    && preg_match(
                        '/<(button|a|div|span)\b[^>]*\baria-controls=["\']' . preg_quote($safeId, '/') . '["\'][^>]*>/i',
                        $html,
                        $tm,
                    ) === 1
                ) {
                    $trigFull = $tm[0];
                    $trigTag = strtolower($tm[1]);
                }
            }

            // 3b.
            if ($trigFull === null) {
                $hamRe = '/<(button|a|div)\b[^>]*>\s*(?:<span[^>]*>\s*<\/span>\s*){2,4}/i';

                // Backward: search the 2000 chars before the nav, the LAST match
                // is the closest one preceding.
                $bwStart = max(0, $navIdx - 2000);
                $before = substr($html, $bwStart, $navIdx - $bwStart);
                $pick = null;
                if (preg_match_all($hamRe, $before, $bm, PREG_SET_ORDER) > 0) {
                    $last = end($bm);
                    if ($last !== false) {
                        $pick = $last[0];
                    }
                }
                if ($pick === null) {
                    $after = substr($html, $navIdx, 2000);
                    if (preg_match($hamRe, $after, $am) === 1) {
                        $pick = $am[0];
                    }
                }
                if ($pick !== null && preg_match('/^<(button|a|div)\b[^>]*>/i', $pick, $open) === 1) {
                    $trigFull = $open[0];
                    $trigTag = strtolower($open[1]);
                }
            }

            if ($trigFull === null || $trigTag === null) {
                continue;
            }
            $ctx->markComponentUsed('amp-bind');

            return $this->applyBurgerBinding($html, $trigFull, $trigTag, $targetOpenTag, $cls, $id, $mod);
        }

        return $html;
    }

    private function applyBurgerBinding(
        string $html,
        string $trigFull,
        string $trigTag,
        string $targetOpenTag,
        string $targetCls,
        string $targetId,
        string $modifier,
    ): string {
        // Auto-generate an id on the target if it has none.
        if ($targetId === '') {
            $targetId = 'amp-mnav-' . (++$this->navCounter);
            $withId = (string) preg_replace('/^(<\w+)/', '$1 id="' . $targetId . '"', $targetOpenTag, 1);
            $html = $this->replaceFirst($html, $targetOpenTag, $withId);
            $targetOpenTag = $withId;
        }

        $stateVar = 'nav_' . (string) preg_replace('/[^a-zA-Z0-9]/', '', $targetId);
        $newClass = $targetCls !== '' ? $targetCls . ' ' . $modifier : $modifier;

        $newTrig = (string) preg_replace(
            '#\s*/?>$#',
            ' on="tap:AMP.setState({' . $stateVar . ':!' . $stateVar . '})"'
                . ' [aria-expanded]="' . $stateVar . "?'true':'false'\">",
            $trigFull,
            1,
        );
        if ($trigTag === 'a') {
            $newTrig = (string) preg_replace('/\s+href=["\'][^"\']*["\']/i', '', $newTrig, 1);
        }
        if ($trigTag !== 'button') {
            if (preg_match('/\brole=/', $newTrig) !== 1) {
                $newTrig = (string) preg_replace('/^<' . $trigTag . '\b/i', '<' . $trigTag . ' role="button"', $newTrig, 1);
            }
            if (preg_match('/\btabindex=/', $newTrig) !== 1) {
                $newTrig = (string) preg_replace('/^<' . $trigTag . '\b/i', '<' . $trigTag . ' tabindex="0"', $newTrig, 1);
            }
        }

        $html = $this->replaceFirst($html, $trigFull, $newTrig);

        $newTarget = (string) preg_replace(
            '#\s*/?>$#',
            ' [class]="' . $stateVar . "?'" . $newClass . "':'" . $targetCls . "'\">",
            $targetOpenTag,
            1,
        );

        return $this->replaceFirst($html, $targetOpenTag, $newTarget);
    }

    private function firstAttrValue(string $attrs, string $name): ?string
    {
        if (preg_match('/\b' . $name . '=["\']([^"\']+)["\']/', $attrs, $m) !== 1) {
            return null;
        }

        return $m[1];
    }

    private function firstToken(string $s): string
    {
        $parts = preg_split('/\s+/', trim($s)) ?: [];

        return $parts[0] ?? '';
    }

    private function replaceFirst(string $haystack, string $needle, string $replacement): string
    {
        $pos = strpos($haystack, $needle);
        if ($pos === false) {
            return $haystack;
        }

        return substr_replace($haystack, $replacement, $pos, strlen($needle));
    }
}
