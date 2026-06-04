<?php

declare(strict_types=1);

namespace AmpConverter\PhpSnippets;

/**
 * Replaces Twig/PHP snippets with alphanumeric placeholders that are valid in
 * any HTML context (attribute values, text content, CSS, etc.), and restores
 * them afterwards.
 *
 * Mirrors `preserveDynamics` / `restoreDynamics` from the Node reference
 * converter (xampphsZNphsEND tokens).
 *
 * Pattern order is intentional: longer Twig statements (`{% %}`) before
 * variables (`{{ }}`) before comments (`{# #}`) before PHP, so a `{{ var.{% %} }}`-style
 * nesting doesn't get half-replaced.
 */
final class SnippetMasker
{
    public const string PLACEHOLDER_PREFIX = 'xampphsZ';
    public const string PLACEHOLDER_SUFFIX = 'phsEND';
    public const string PLACEHOLDER_REGEX = '/xampphsZ(\d+)phsEND/';

    /** @var list<non-empty-string> */
    private const array PATTERNS = [
        '/{%[\s\S]*?%}/',     // Twig statements
        '/{{[\s\S]*?}}/',     // Twig expressions
        '/{#[\s\S]*?#}/',     // Twig comments
        '/<\?[\s\S]*?\?>/',   // PHP open/close
    ];

    public static function placeholder(int $index): string
    {
        return self::PLACEHOLDER_PREFIX . $index . self::PLACEHOLDER_SUFFIX;
    }

    /**
     * @return array{cleaned: string, stash: list<string>}
     */
    public static function mask(string $html): array
    {
        $stash = [];
        $cleaned = $html;
        foreach (self::PATTERNS as $pattern) {
            $cleaned = preg_replace_callback(
                $pattern,
                static function (array $matches) use (&$stash): string {
                    $index = count($stash);
                    $stash[] = $matches[0];
                    return self::placeholder($index);
                },
                $cleaned,
            ) ?? $cleaned;
        }

        return ['cleaned' => $cleaned, 'stash' => $stash];
    }

    /**
     * @param list<string> $stash
     */
    public static function unmask(string $text, array $stash): string
    {
        return preg_replace_callback(
            self::PLACEHOLDER_REGEX,
            static function (array $matches) use ($stash): string {
                $index = (int) $matches[1];
                return $stash[$index] ?? $matches[0];
            },
            $text,
        ) ?? $text;
    }
}
