<?php

declare(strict_types=1);

namespace AmpConverter\Transformer;

use AmpConverter\Context;
use AmpConverter\Transformer;

/**
 * Maps common author FAQ patterns to the native AMP <amp-accordion>
 * component.
 *
 * Port of wrapFaqInAccordion + helpers from
 * tools/convert-rendered-to-amp.js (~lines 660-1140 plus the CSS post-
 * process at ~218 and 1106-1138).
 *
 * Four detection variants run in order on the HTML body, each fully
 * independent so they compose without interference:
 *
 *   V1 Container: a block-level wrapper with itemtype="FAQPage" or a
 *      class/id matching the broad FAQ marker (faq, faq-XXX, qa-XXX,
 *      questions-XXX, frequently-asked, accordion-container/list/...)
 *      → all inner FAQ items get extracted via permissive matching
 *      (schema.org Question, faq-item / accordion-item / qa-pair
 *      whitelisted classes, dt/dd pairs, or hN+p sibling pairs).
 *   V2 dl/dt/dd: guard is parent FAQ-marker OR >=50% dt-content ending
 *      in '?'. Without that guard we leave plain dl alone (intentional
 *      — sweetbonanza-style plain definition lists must NOT get wrapped).
 *   V3 Sibling Questions: 2+ consecutive
 *      `<div itemtype=".../Question">` blocks at the same level
 *      become one accordion.
 *   V4 hN+p inside FAQ-marker parent: 2+ heading/paragraph pairs inside
 *      a wrapper with a FAQ marker on class/id get wrapped, parent kept.
 *
 * Once any variant fires, the transformer also rewrites the
 * <style amp-custom> block:
 *   - injectQuestionClassDefaults — equal-specificity reset
 *     `.<q-class>{background:inherit;border:inherit}` for each detected
 *     question class, so AMP's runtime grey-header default does not
 *     override the author's design.
 *   - patchFaqCssSpecificity — for every CSS rule whose selector list
 *     mentions an inner-FAQ class token, emit comma-list copies prefixed
 *     with each detected container selector. Specificity goes from
 *     (0,1,0) to (0,2,0)+, beating AMP runtime CSS.
 *   - patchAccordionCss — rewrite `.faq-item.open` style selectors to
 *     `.faq-item[expanded]`, and strip `max-height:0 / display:none /
 *     visibility:hidden` from default answer-class rules (AMP itself
 *     handles the collapse, leftover blockers prevent expansion).
 *
 * Marks amp-accordion as a used component on Context when at least one
 * variant fires.
 */
final class FaqToAccordion implements Transformer
{
    private const SECTION_CLASSES = ['faq-item', 'accordion-item'];
    private const TOGGLE_MODIFIERS = ['open', 'opened', 'expanded', 'is-open', 'is-active', 'active', 'show', 'shown'];
    private const ANSWER_CLASSES = ['faq-answer', 'accordion-body', 'accordion-content'];

    /** @var list<string> */
    private array $containerPrefixes = [];

    /** @var array<string, true> set of inner-FAQ class tokens collected during wrap */
    private array $innerClasses = [];

    /** @var array<string, true> set of question-class tokens for CSS injection */
    private array $questionClasses = [];

    public function apply(string $html, Context $ctx): string
    {
        $this->containerPrefixes = [];
        $this->innerClasses = [];
        $this->questionClasses = [];

        $foundAny = false;
        foreach (['wrapFaqContainerInAccordion', 'wrapDlInAccordion', 'wrapSiblingQuestionsInAccordion', 'wrapHeadingPatternInAccordion'] as $method) {
            /** @var array{html: string, found: bool} $r */
            $r = $this->{$method}($html);
            $html = $r['html'];
            if ($r['found']) {
                $foundAny = true;
            }
        }

        if (!$foundAny) {
            return $html;
        }

        $ctx->markComponentUsed('amp-accordion');
        foreach (array_keys($this->questionClasses) as $cls) {
            if (!in_array($cls, $ctx->faqQuestionClasses, true)) {
                $ctx->faqQuestionClasses[] = $cls;
            }
        }

        return $this->postprocessAmpCustomCss($html);
    }

    // ----- HTML helpers -----

    /**
     * Find the matching closing `</tagName>` starting from a known
     * opening-tag offset. Returns the index AFTER the closing tag, or
     * -1 when balance breaks.
     */
    private function findMatchingClose(string $html, int $startIdx, string $tagName): int
    {
        $re = '/<(\/?)' . $tagName . '\b[^>]*>/i';
        $depth = 0;
        $offset = $startIdx;
        while (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            if ($m[1][0] === '/') {
                $depth--;
                if ($depth === 0) {
                    return $m[0][1] + strlen($m[0][0]);
                }
            } else {
                $depth++;
            }
            $offset = $m[0][1] + strlen($m[0][0]);
        }

        return -1;
    }

    /**
     * @return array{cls: string, id: string, str: string}
     */
    private function extractClassAndId(string $attrs): array
    {
        $cls = preg_match('/\bclass=["\']([^"\']+)["\']/', $attrs, $cm) === 1 ? $cm[1] : '';
        $id = preg_match('/\bid=["\']([^"\']+)["\']/', $attrs, $im) === 1 ? $im[1] : '';
        $str = ($cls !== '' ? ' class="' . $cls . '"' : '') . ($id !== '' ? ' id="' . $id . '"' : '');

        return ['cls' => $cls, 'id' => $id, 'str' => $str];
    }

    private function selectorPrefixFromAttrs(string $attrs): ?string
    {
        $ext = $this->extractClassAndId($attrs);
        if ($ext['id'] !== '') {
            return '#' . $ext['id'];
        }
        if ($ext['cls'] !== '') {
            $parts = preg_split('/\s+/', $ext['cls']) ?: [];
            $first = $parts[0] ?? '';
            if ($first !== '') {
                return '.' . $first;
            }
        }

        return null;
    }

    private function collectClassesFromHtml(string $html): void
    {
        if (preg_match_all('/\bclass=["\']([^"\']+)["\']/', $html, $m) > 0) {
            foreach ($m[1] as $val) {
                foreach (preg_split('/\s+/', $val) ?: [] as $c) {
                    if ($c !== '') {
                        $this->innerClasses[$c] = true;
                    }
                }
            }
        }
    }

    // ----- Question / Answer extraction -----

    /**
     * Return ['content' => string, 'cls' => string] or null.
     *
     * @return ?array{content: string, cls: string}
     */
    private function extractFaqQuestion(string $itemInner): ?array
    {
        // A: <button class="faq-q…">  — fixed tag, attrs in group 1.
        if (preg_match(
            '/<button\b([^>]*\bclass=["\'][^"\']*\b(?:faq-q|faq-question|accordion-header|accordion-title|accordion-toggle)\b[^"\']*["\'][^>]*)>/i',
            $itemInner,
            $m,
            PREG_OFFSET_CAPTURE,
        ) === 1) {
            $q = $this->finishQuestionExtract($itemInner, 'button', $m[0][1], strlen($m[0][0]), $m[1][0]);
            if ($q !== null) {
                return $q;
            }
        }

        // B: <hN|div|span class="faq-q…">  — captured tag in group 1, attrs in group 2.
        if (preg_match(
            '/<(h[1-6]|div|span)\b([^>]*\bclass=["\'][^"\']*\b(?:faq-q|faq-question|accordion-header|accordion-title|accordion-toggle)\b[^"\']*["\'][^>]*)>/i',
            $itemInner,
            $m,
            PREG_OFFSET_CAPTURE,
        ) === 1) {
            $q = $this->finishQuestionExtract($itemInner, strtolower($m[1][0]), $m[0][1], strlen($m[0][0]), $m[2][0]);
            if ($q !== null) {
                return $q;
            }
        }

        // C: itemprop="name" fallback — captured tag in group 1, attrs in group 2.
        if (preg_match(
            '/<([a-z][\w-]*)\b([^>]*\bitemprop=["\']name["\'][^>]*)>/i',
            $itemInner,
            $m,
            PREG_OFFSET_CAPTURE,
        ) === 1) {
            $q = $this->finishQuestionExtract($itemInner, strtolower($m[1][0]), $m[0][1], strlen($m[0][0]), $m[2][0]);
            if ($q !== null) {
                return $q;
            }
        }

        return null;
    }

    /**
     * @return ?array{content: string, cls: string}
     */
    private function finishQuestionExtract(
        string $itemInner,
        string $tag,
        int $idx,
        int $openTagLen,
        string $attrs,
    ): ?array {
        $end = $this->findMatchingClose($itemInner, $idx, $tag);
        if ($end === -1) {
            return null;
        }
        $inner = substr($itemInner, $idx + $openTagLen, $end - ($idx + $openTagLen) - strlen("</{$tag}>"));
        $ext = $this->extractClassAndId($attrs);

        return ['content' => trim($inner), 'cls' => $ext['cls']];
    }

    /**
     * @return ?array{content: string, cls: string}
     */
    private function extractFaqAnswer(string $itemInner): ?array
    {
        // A: schema.org acceptedAnswer / Answer itemtype.
        if (preg_match(
            '/<([a-z][\w-]*)\b([^>]*(?:\bitemprop=["\']acceptedAnswer["\']|\bitemtype=["\'][^"\']*\/Answer["\'])[^>]*)>/i',
            $itemInner,
            $m,
            PREG_OFFSET_CAPTURE,
        ) === 1) {
            $idx = $m[0][1];
            $tag = strtolower($m[1][0]);
            $openTagLen = strlen($m[0][0]);
            $end = $this->findMatchingClose($itemInner, $idx, $tag);
            if ($end !== -1) {
                $inner = substr($itemInner, $idx + $openTagLen, $end - ($idx + $openTagLen) - strlen("</{$tag}>"));
                $ext = $this->extractClassAndId($m[2][0]);

                return ['content' => trim($inner), 'cls' => $ext['cls']];
            }
        }
        // B: class whitelist.
        if (preg_match(
            '/<(div|p|section)\b([^>]*\bclass=["\'][^"\']*\b(?:faq-a|faq-answer|accordion-body|accordion-content|qa-a)\b[^"\']*["\'][^>]*)>/i',
            $itemInner,
            $m,
            PREG_OFFSET_CAPTURE,
        ) === 1) {
            $idx = $m[0][1];
            $tag = strtolower($m[1][0]);
            $openTagLen = strlen($m[0][0]);
            $end = $this->findMatchingClose($itemInner, $idx, $tag);
            if ($end !== -1) {
                $inner = substr($itemInner, $idx + $openTagLen, $end - ($idx + $openTagLen) - strlen("</{$tag}>"));
                $ext = $this->extractClassAndId($m[2][0]);

                return ['content' => trim($inner), 'cls' => $ext['cls']];
            }
        }

        return null;
    }

    /**
     * @param array{content: string, cls: string} $q
     * @param array{content: string, cls: string} $a
     */
    private function buildAccordionSection(string $itemAttrs, array $q, array $a): string
    {
        $itemExt = $this->extractClassAndId($itemAttrs);
        $qCls = $q['cls'] !== '' ? ' class="' . $q['cls'] . '"' : '';
        $aCls = $a['cls'] !== '' ? ' class="' . $a['cls'] . '"' : '';

        return '<section' . $itemExt['str'] . '>'
            . '<header' . $qCls . '>' . $q['content'] . '</header>'
            . '<div' . $aCls . '>' . $a['content'] . '</div>'
            . '</section>';
    }

    // ----- V1: container-based -----

    private function isFaqContainerAttrs(string $attrs): bool
    {
        if (preg_match('/\bitemtype=["\'][^"\']*FAQPage[^"\']*["\']/i', $attrs) === 1) {
            return true;
        }
        if (preg_match_all('/(?:class|id)=["\']([^"\']+)["\']/i', $attrs, $m) === 0) {
            return false;
        }
        $joined = implode(' ', $m[1]);
        if (preg_match('/(?:^|[\s_-])(?:faqs?|frequently[-_]?asked|q[-_]and[-_]a|questions?[-_][\w-]+|qa[-_][\w-]+|accordion[-_](?:list|section|block|wrap|container))(?:$|[\s_-])/i', $joined) === 1) {
            return true;
        }

        return preg_match('/\bfaq[-_][\w-]+/i', $joined) === 1;
    }

    /**
     * @return array{html: string, found: bool}
     */
    private function wrapFaqContainerInAccordion(string $html): array
    {
        $blockOpenRe = '/<(div|section|article|aside)\b([^>]*)>/i';
        $out = '';
        $lastIdx = 0;
        $found = false;
        $cursor = 0;
        while ($cursor < strlen($html) && preg_match($blockOpenRe, $html, $m, PREG_OFFSET_CAPTURE, $cursor) === 1) {
            $tag = $m[1][0];
            $attrs = $m[2][0];
            $startIdx = $m[0][1];
            $openTagLen = strlen($m[0][0]);
            $cursor = $startIdx + 1;
            if (!$this->isFaqContainerAttrs($attrs)) {
                continue;
            }
            $endIdx = $this->findMatchingClose($html, $startIdx, $tag);
            if ($endIdx === -1) {
                continue;
            }
            $containerInner = substr(
                $html,
                $startIdx + $openTagLen,
                $endIdx - ($startIdx + $openTagLen) - strlen("</{$tag}>"),
            );
            $sections = $this->collectFaqItemsPermissive($containerInner);
            if ($sections === []) {
                continue;
            }
            $ext = $this->extractClassAndId($attrs);
            $prefix = $this->selectorPrefixFromAttrs($attrs);
            if ($prefix !== null) {
                $this->containerPrefixes[] = $prefix;
            }
            $this->collectClassesFromHtml($containerInner);
            $out .= substr($html, $lastIdx, $startIdx - $lastIdx);
            $out .= '<amp-accordion' . $ext['str'] . '>' . implode('', $sections) . '</amp-accordion>';
            $lastIdx = $endIdx;
            $cursor = $endIdx;
            $found = true;
        }
        if (!$found) {
            return ['html' => $html, 'found' => false];
        }
        $out .= substr($html, $lastIdx);

        return ['html' => $out, 'found' => true];
    }

    /**
     * @return list<string>
     */
    private function collectFaqItemsPermissive(string $containerInner): array
    {
        $sections = [];

        // Strategy A: marked item tags.
        $itemRe = '/<(div|section|article|li)\b([^>]*(?:\bitemtype=["\'][^"\']*\/Question["\']|\bclass=["\'][^"\']*\b(?:faq-item|faq-entry|accordion-item|qa-item|question-item|qa-pair|qa-entry|faq-row|qa-row)\b[^"\']*["\'])[^>]*)>/i';
        $cursor = 0;
        while ($cursor < strlen($containerInner) && preg_match($itemRe, $containerInner, $m, PREG_OFFSET_CAPTURE, $cursor) === 1) {
            $tag = strtolower($m[1][0]);
            $itemStart = $m[0][1];
            $openTagLen = strlen($m[0][0]);
            $cursor = $itemStart + 1;
            $itemEnd = $this->findMatchingClose($containerInner, $itemStart, $tag);
            if ($itemEnd === -1) {
                continue;
            }
            $itemInner = substr(
                $containerInner,
                $itemStart + $openTagLen,
                $itemEnd - ($itemStart + $openTagLen) - strlen("</{$tag}>"),
            );
            $q = $this->extractFaqQuestion($itemInner);
            $a = $this->extractFaqAnswer($itemInner);
            $cursor = $itemEnd;
            if ($q === null || $a === null) {
                continue;
            }
            if ($q['cls'] !== '') {
                $this->questionClasses[$q['cls']] = true;
            }
            $sections[] = $this->buildAccordionSection($m[2][0], $q, $a);
        }
        if ($sections !== []) {
            return $sections;
        }

        // Strategy B: dt/dd pairs.
        if (preg_match_all('#<dt\b([^>]*)>([\s\S]*?)</dt>\s*<dd\b([^>]*)>([\s\S]*?)</dd>#i', $containerInner, $dm, PREG_SET_ORDER) > 0) {
            foreach ($dm as $p) {
                $dtExt = $this->extractClassAndId($p[1]);
                $ddExt = $this->extractClassAndId($p[3]);
                if ($dtExt['cls'] !== '') {
                    $this->questionClasses[$dtExt['cls']] = true;
                }
                $sections[] = '<section><header'
                    . ($dtExt['cls'] !== '' ? ' class="' . $dtExt['cls'] . '"' : '') . '>'
                    . trim($p[2]) . '</header><div'
                    . ($ddExt['cls'] !== '' ? ' class="' . $ddExt['cls'] . '"' : '') . '>'
                    . trim($p[4]) . '</div></section>';
            }
        }
        if ($sections !== []) {
            return $sections;
        }

        // Strategy C: heading + sibling block.
        if (preg_match_all('#<(h[2-5])\b([^>]*)>([\s\S]*?)</\1>\s*<(p|div)\b([^>]*)>([\s\S]*?)</\4>#i', $containerInner, $pm, PREG_SET_ORDER) > 0) {
            foreach ($pm as $p) {
                $hExt = $this->extractClassAndId($p[2]);
                $bExt = $this->extractClassAndId($p[5]);
                if ($hExt['cls'] !== '') {
                    $this->questionClasses[$hExt['cls']] = true;
                }
                $sections[] = '<section><header'
                    . ($hExt['cls'] !== '' ? ' class="' . $hExt['cls'] . '"' : '') . '>'
                    . trim($p[3]) . '</header><div'
                    . ($bExt['cls'] !== '' ? ' class="' . $bExt['cls'] . '"' : '') . '>'
                    . trim($p[6]) . '</div></section>';
            }
        }

        return $sections;
    }

    // ----- V2: dl/dt/dd -----

    /**
     * @return array{html: string, found: bool}
     */
    private function wrapDlInAccordion(string $html): array
    {
        $dlRe = '/<dl\b([^>]*)>/i';
        $out = '';
        $lastIdx = 0;
        $found = false;
        $cursor = 0;
        while ($cursor < strlen($html) && preg_match($dlRe, $html, $m, PREG_OFFSET_CAPTURE, $cursor) === 1) {
            $startIdx = $m[0][1];
            $dlAttrs = $m[1][0];
            $openTagLen = strlen($m[0][0]);
            $cursor = $startIdx + 1;
            $endIdx = $this->findMatchingClose($html, $startIdx, 'dl');
            if ($endIdx === -1) {
                continue;
            }
            $inner = substr(
                $html,
                $startIdx + $openTagLen,
                $endIdx - ($startIdx + $openTagLen) - strlen('</dl>'),
            );
            if (preg_match_all('#<dt\b([^>]*)>([\s\S]*?)</dt>\s*<dd\b([^>]*)>([\s\S]*?)</dd>#i', $inner, $pairs, PREG_SET_ORDER) === 0 || count($pairs) < 2) {
                continue;
            }
            $hasMarker = preg_match('/class=["\'][^"\']*\b(?:faq|qa|questions|frequently|ask)\b[^"\']*["\']|id=["\'][^"\']*\b(?:faq|qa|questions|frequently|ask)\b[^"\']*["\']/i', $dlAttrs) === 1;
            $questionLike = 0;
            foreach ($pairs as $p) {
                $plain = (string) preg_replace('/<[^>]+>/', '', $p[2]);
                if (preg_match('/\?\s*$/', $plain) === 1) {
                    $questionLike++;
                }
            }
            if (!$hasMarker && ($questionLike / count($pairs)) < 0.5) {
                $cursor = $endIdx;
                continue;
            }
            $sections = [];
            foreach ($pairs as $p) {
                $dtExt = $this->extractClassAndId($p[1]);
                $ddExt = $this->extractClassAndId($p[3]);
                if ($dtExt['cls'] !== '') {
                    $this->questionClasses[$dtExt['cls']] = true;
                }
                $sections[] = '<section><header'
                    . ($dtExt['cls'] !== '' ? ' class="' . $dtExt['cls'] . '"' : '') . '>'
                    . trim($p[2]) . '</header><div'
                    . ($ddExt['cls'] !== '' ? ' class="' . $ddExt['cls'] . '"' : '') . '>'
                    . trim($p[4]) . '</div></section>';
            }
            $ext = $this->extractClassAndId($dlAttrs);
            $out .= substr($html, $lastIdx, $startIdx - $lastIdx);
            $out .= '<amp-accordion' . $ext['str'] . '>' . implode('', $sections) . '</amp-accordion>';
            $lastIdx = $endIdx;
            $cursor = $endIdx;
            $found = true;
        }
        if (!$found) {
            return ['html' => $html, 'found' => false];
        }
        $out .= substr($html, $lastIdx);

        return ['html' => $out, 'found' => true];
    }

    // ----- V3: sibling Questions -----

    /**
     * @return array{html: string, found: bool}
     */
    private function wrapSiblingQuestionsInAccordion(string $html): array
    {
        $qRe = '/<div\b[^>]*itemtype=["\'][^"\']*\/Question["\'][^>]*>/i';
        $out = '';
        $lastIdx = 0;
        $found = false;
        $cursor = 0;

        while ($cursor < strlen($html)) {
            if (preg_match($qRe, $html, $first, PREG_OFFSET_CAPTURE, $cursor) !== 1) {
                break;
            }
            $items = [];
            $scanFrom = $first[0][1];
            while (true) {
                if (preg_match($qRe, $html, $m, PREG_OFFSET_CAPTURE, $scanFrom) !== 1 || $m[0][1] !== $scanFrom) {
                    break;
                }
                $itemEnd = $this->findMatchingClose($html, $m[0][1], 'div');
                if ($itemEnd === -1) {
                    break;
                }
                $openTag = $m[0][0];
                $items[] = ['start' => $m[0][1], 'end' => $itemEnd, 'attrs' => substr($openTag, 4, strlen($openTag) - 5)];
                $nx = $itemEnd;
                while ($nx < strlen($html) && preg_match('/\s/', $html[$nx]) === 1) {
                    $nx++;
                }
                $scanFrom = $nx;
            }
            if (count($items) < 2) {
                $cursor = $first[0][1] + 1;
                continue;
            }
            $sections = [];
            foreach ($items as $it) {
                $openEnd = strpos(substr($html, $it['start']), '>');
                if ($openEnd === false) {
                    continue;
                }
                $innerStart = $it['start'] + $openEnd + 1;
                $itemInner = substr($html, $innerStart, $it['end'] - $innerStart - strlen('</div>'));
                $q = $this->extractFaqQuestion($itemInner);
                $a = $this->extractFaqAnswer($itemInner);
                if ($q === null || $a === null) {
                    continue;
                }
                if ($q['cls'] !== '') {
                    $this->questionClasses[$q['cls']] = true;
                }
                $sections[] = $this->buildAccordionSection($it['attrs'], $q, $a);
            }
            if (count($sections) < 2) {
                $cursor = $first[0][1] + 1;
                continue;
            }
            $out .= substr($html, $lastIdx, $first[0][1] - $lastIdx);
            $out .= '<amp-accordion>' . implode('', $sections) . '</amp-accordion>';
            $lastIdx = $items[count($items) - 1]['end'];
            $cursor = $lastIdx;
            $found = true;
        }
        if (!$found) {
            return ['html' => $html, 'found' => false];
        }
        $out .= substr($html, $lastIdx);

        return ['html' => $out, 'found' => true];
    }

    // ----- V4: hN+p inside FAQ-marker parent -----

    /**
     * @return array{html: string, found: bool}
     */
    private function wrapHeadingPatternInAccordion(string $html): array
    {
        $parentRe = '/<(div|section|article|aside)\b([^>]*\b(?:class|id)=["\'][^"\']*\b(?:faq|qa|questions|frequently-asked|frequently_asked|ask)\b[^"\']*["\'][^>]*)>/i';
        $out = '';
        $lastIdx = 0;
        $found = false;
        $cursor = 0;
        while ($cursor < strlen($html) && preg_match($parentRe, $html, $m, PREG_OFFSET_CAPTURE, $cursor) === 1) {
            $tag = strtolower($m[1][0]);
            $startIdx = $m[0][1];
            $openTagLen = strlen($m[0][0]);
            $cursor = $startIdx + 1;
            $endIdx = $this->findMatchingClose($html, $startIdx, $tag);
            if ($endIdx === -1) {
                continue;
            }
            $inner = substr(
                $html,
                $startIdx + $openTagLen,
                $endIdx - ($startIdx + $openTagLen) - strlen("</{$tag}>"),
            );
            if (preg_match_all('#<(h[2-5])\b([^>]*)>([\s\S]*?)</\1>\s*<p\b([^>]*)>([\s\S]*?)</p>#i', $inner, $pairs, PREG_SET_ORDER) === 0 || count($pairs) < 2) {
                continue;
            }
            if (str_contains($inner, '<amp-accordion')) {
                continue;
            }
            $sections = [];
            foreach ($pairs as $p) {
                $hExt = $this->extractClassAndId($p[2]);
                $pExt = $this->extractClassAndId($p[4]);
                if ($hExt['cls'] !== '') {
                    $this->questionClasses[$hExt['cls']] = true;
                }
                $sections[] = '<section><header'
                    . ($hExt['cls'] !== '' ? ' class="' . $hExt['cls'] . '"' : '') . '>'
                    . trim($p[3]) . '</header><div'
                    . ($pExt['cls'] !== '' ? ' class="' . $pExt['cls'] . '"' : '') . '><p>'
                    . trim($p[5]) . '</p></div></section>';
            }
            $newInner = '<amp-accordion>' . implode('', $sections) . '</amp-accordion>';
            $out .= substr($html, $lastIdx, $startIdx - $lastIdx);
            $out .= $m[0][0] . $newInner . '</' . $tag . '>';
            $lastIdx = $endIdx;
            $cursor = $endIdx;
            $found = true;
        }
        if (!$found) {
            return ['html' => $html, 'found' => false];
        }
        $out .= substr($html, $lastIdx);

        return ['html' => $out, 'found' => true];
    }

    // ----- CSS post-process -----

    private function postprocessAmpCustomCss(string $html): string
    {
        return (string) preg_replace_callback(
            '#<style amp-custom>([\s\S]*?)</style>#i',
            function (array $m): string {
                $css = $m[1];
                $css = $this->patchAccordionCss($css);
                $css = $this->patchFaqCssSpecificity($css);
                $css = $this->injectQuestionClassDefaults($css);

                return '<style amp-custom>' . $css . '</style>';
            },
            $html,
            1,
        );
    }

    private function patchAccordionCss(string $css): string
    {
        foreach (self::SECTION_CLASSES as $sec) {
            foreach (self::TOGGLE_MODIFIERS as $mod) {
                $css = (string) preg_replace('/\.' . $sec . '\.' . $mod . '(?![\w-])/i', '.' . $sec . '[expanded]', $css);
            }
        }
        $ansPattern = implode('|', array_map(static fn (string $c): string => '\.' . $c, self::ANSWER_CLASSES));
        $ruleRe = '/([^{}]*(?:' . $ansPattern . ')(?![\w-])[^{}]*?)\{([^}]*)\}/i';

        return (string) preg_replace_callback(
            $ruleRe,
            static function (array $m): string {
                if (preg_match('/\[expanded\]/', $m[1]) === 1) {
                    return $m[0];
                }
                $decls = (string) preg_replace('/\s*max-height\s*:\s*0(?:\.0+)?(?:px|em|rem|%)?\s*;?/i', '', $m[2]);
                $decls = (string) preg_replace('/\s*display\s*:\s*none\s*;?/i', '', $decls);
                $decls = (string) preg_replace('/\s*visibility\s*:\s*hidden\s*;?/i', '', $decls);

                return $m[1] . '{' . $decls . '}';
            },
            $css,
        );
    }

    private function patchFaqCssSpecificity(string $css): string
    {
        if ($this->containerPrefixes === [] || $this->innerClasses === []) {
            return $css;
        }
        $alt = implode('|', array_map(
            static fn (string $c): string => str_replace('-', '\\-', $c),
            array_keys($this->innerClasses),
        ));
        $classRe = '/\.(?:' . $alt . ')(?![\w-])/';
        $prefixes = $this->containerPrefixes;

        return (string) preg_replace_callback(
            '/([^{}]+)\{([^}]*)\}/',
            static function (array $m) use ($classRe, $prefixes): string {
                $selectors = array_values(array_filter(array_map(
                    'trim',
                    explode(',', $m[1]),
                ), static fn (string $s): bool => $s !== ''));
                $matching = array_values(array_filter(
                    $selectors,
                    static fn (string $s): bool => preg_match($classRe, $s) === 1,
                ));
                if ($matching === []) {
                    return $m[0];
                }
                $additions = [];
                foreach ($matching as $sel) {
                    foreach ($prefixes as $prefix) {
                        $additions[] = $prefix . ' ' . $sel;
                    }
                }

                return implode(', ', array_merge($selectors, $additions)) . '{' . $m[2] . '}';
            },
            $css,
        );
    }

    private function injectQuestionClassDefaults(string $css): string
    {
        $tokens = [];
        foreach (array_keys($this->questionClasses) as $cls) {
            foreach (preg_split('/\s+/', $cls) ?: [] as $t) {
                if ($t !== '') {
                    $tokens[$t] = true;
                }
            }
        }
        if ($tokens === []) {
            return $css;
        }
        $rules = implode("\n", array_map(
            static fn (string $t): string => '.' . $t . '{background:inherit;border:inherit}',
            array_keys($tokens),
        ));

        return $rules . "\n" . $css;
    }
}
