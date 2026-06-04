<?php

declare(strict_types=1);

namespace ZergiusEggstream\AmpConverter\Tests\Unit\PhpSnippets;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ZergiusEggstream\AmpConverter\PhpSnippets\SnippetMasker;

final class SnippetMaskerTest extends TestCase
{
    #[Test]
    public function masks_twig_statement(): void
    {
        $input = 'before {% if x %}body{% endif %} after';
        $result = SnippetMasker::mask($input);

        self::assertSame('before xampphsZ0phsENDbodyxampphsZ1phsEND after', $result['cleaned']);
        self::assertSame(['{% if x %}', '{% endif %}'], $result['stash']);
    }

    #[Test]
    public function masks_twig_expression(): void
    {
        $input = 'hello {{ name }} and {{ user.email }}';
        $result = SnippetMasker::mask($input);

        self::assertSame('hello xampphsZ0phsEND and xampphsZ1phsEND', $result['cleaned']);
        self::assertSame(['{{ name }}', '{{ user.email }}'], $result['stash']);
    }

    #[Test]
    public function masks_twig_comment(): void
    {
        $input = 'a {# todo: x #} b';
        $result = SnippetMasker::mask($input);

        self::assertSame('a xampphsZ0phsEND b', $result['cleaned']);
        self::assertSame(['{# todo: x #}'], $result['stash']);
    }

    #[Test]
    public function masks_php_snippet(): void
    {
        $input = 'before <?php echo $x; ?> after <?= $y ?> end';
        $result = SnippetMasker::mask($input);

        self::assertSame('before xampphsZ0phsEND after xampphsZ1phsEND end', $result['cleaned']);
        self::assertSame(['<?php echo $x; ?>', '<?= $y ?>'], $result['stash']);
    }

    #[Test]
    public function preserves_pattern_order_statements_before_expressions(): void
    {
        // {% raw %}{{ x }}{% endraw %} — outer {% %} must mask first so inner {{ }} stays inside placeholder
        $input = '{% if cond %}{{ var }}{% endif %}';
        $result = SnippetMasker::mask($input);

        // Three placeholders: two {% %} + one {{ }}. The inner {{ var }} is captured separately
        // (it lives between the two outer tags, not inside them).
        self::assertSame('xampphsZ0phsENDxampphsZ2phsENDxampphsZ1phsEND', $result['cleaned']);
        self::assertSame(['{% if cond %}', '{% endif %}', '{{ var }}'], $result['stash']);
    }

    #[Test]
    public function roundtrip_preserves_original(): void
    {
        $input = <<<HTML
<h1>{{ title }}</h1>
{% for item in items %}
    <li><?= htmlspecialchars(\$item) ?></li>
{% endfor %}
{# inline comment #}
HTML;
        $masked = SnippetMasker::mask($input);
        $restored = SnippetMasker::unmask($masked['cleaned'], $masked['stash']);

        self::assertSame($input, $restored);
    }

    #[Test]
    public function roundtrip_through_intermediate_html_changes(): void
    {
        // Simulates a transformer that rewrites surrounding HTML while leaving placeholders intact.
        $input = '<img src="{{ photo }}" /><?php $foo; ?>';
        $masked = SnippetMasker::mask($input);

        // pretend a transformer rewrote <img> to <amp-img>, didn't touch placeholders
        $rewritten = str_replace('<img', '<amp-img', $masked['cleaned']);

        $restored = SnippetMasker::unmask($rewritten, $masked['stash']);
        self::assertSame('<amp-img src="{{ photo }}" /><?php $foo; ?>', $restored);
    }

    #[Test]
    public function empty_input_returns_empty_stash(): void
    {
        $result = SnippetMasker::mask('');

        self::assertSame('', $result['cleaned']);
        self::assertSame([], $result['stash']);
    }

    #[Test]
    public function input_without_dynamics_is_untouched(): void
    {
        $input = '<html><body><h1>Static</h1></body></html>';
        $result = SnippetMasker::mask($input);

        self::assertSame($input, $result['cleaned']);
        self::assertSame([], $result['stash']);
    }

    #[Test]
    public function unmask_with_unknown_index_leaves_placeholder_as_is(): void
    {
        // Defensive: if a transformer accidentally emits a new placeholder-shaped string,
        // we don't substitute garbage — we leave it alone.
        $result = SnippetMasker::unmask('hello xampphsZ99phsEND world', ['only-one']);

        self::assertSame('hello xampphsZ99phsEND world', $result);
    }

    #[Test]
    public function placeholder_helper_generates_expected_format(): void
    {
        self::assertSame('xampphsZ0phsEND', SnippetMasker::placeholder(0));
        self::assertSame('xampphsZ7phsEND', SnippetMasker::placeholder(7));
        self::assertSame('xampphsZ1024phsEND', SnippetMasker::placeholder(1024));
    }

    #[Test]
    public function placeholder_in_attribute_value_survives_html_safe(): void
    {
        // The placeholder is alphanumeric so it's a valid attribute value, valid CSS identifier, etc.
        // This test documents that property — if anything ever changes the format, this fails loudly.
        $placeholder = SnippetMasker::placeholder(42);

        self::assertMatchesRegularExpression('/^[a-zA-Z0-9]+$/', $placeholder);
    }

    #[Test]
    public function multiline_twig_and_php_blocks(): void
    {
        // [\s\S]*? in regex means dynamics can span multiple lines.
        $input = "{% if\n  cond %}body{% endif %}<?php\n  echo 1;\n?>";
        $result = SnippetMasker::mask($input);

        self::assertCount(3, $result['stash']);
        self::assertSame("{% if\n  cond %}", $result['stash'][0]);
        self::assertSame('{% endif %}', $result['stash'][1]);
        self::assertSame("<?php\n  echo 1;\n?>", $result['stash'][2]);
    }
}
