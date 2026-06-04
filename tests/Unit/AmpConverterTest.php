<?php

declare(strict_types=1);

namespace AmpConverter\Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use AmpConverter\AmpConverter;
use AmpConverter\Context;
use AmpConverter\Transformer;

final class AmpConverterTest extends TestCase
{
    #[Test]
    public function runs_transformers_in_order(): void
    {
        /** @var ArrayObject<int, string> $calls */
        $calls = new ArrayObject();
        $converter = new AmpConverter([
            $this->recordingTransformer('A', $calls),
            $this->recordingTransformer('B', $calls),
            $this->recordingTransformer('C', $calls),
        ]);

        $converter->convert('input', '/site');

        self::assertSame(['A', 'B', 'C'], $calls->getArrayCopy());
    }

    #[Test]
    public function feeds_each_transformer_output_into_the_next(): void
    {
        $converter = new AmpConverter([
            $this->upperTransformer(),
            $this->wrapTransformer('('),
        ]);

        $result = $converter->convert('hello', '/site');

        self::assertSame('(HELLO)', $result->html);
    }

    #[Test]
    public function exposes_used_components_collected_via_context(): void
    {
        $converter = new AmpConverter([
            $this->componentMarkingTransformer(['amp-img', 'amp-iframe']),
            $this->componentMarkingTransformer(['amp-img']), // duplicate intentional — should dedupe
        ]);

        $result = $converter->convert('html', '/site');

        $sorted = $result->usedComponents;
        sort($sorted);
        self::assertSame(['amp-iframe', 'amp-img'], $sorted);
    }

    #[Test]
    public function exposes_warnings_collected_via_context(): void
    {
        $converter = new AmpConverter([
            $this->warningTransformer('dropped malformed <img>'),
            $this->warningTransformer('SVG missing viewBox'),
        ]);

        $result = $converter->convert('html', '/site');

        self::assertSame(['dropped malformed <img>', 'SVG missing viewBox'], $result->warnings);
        self::assertTrue($result->hasWarnings());
    }

    #[Test]
    public function passes_site_root_into_context(): void
    {
        /** @var ArrayObject<string, ?string> $tracker */
        $tracker = new ArrayObject(['siteRoot' => null]);
        $converter = new AmpConverter([
            $this->siteRootCapturingTransformer($tracker),
        ]);

        $converter->convert('html', '/var/www/site');

        self::assertSame('/var/www/site', $tracker['siteRoot']);
    }

    #[Test]
    public function empty_pipeline_returns_input_unchanged(): void
    {
        $converter = new AmpConverter([]);

        $result = $converter->convert('untouched', '/site');

        self::assertSame('untouched', $result->html);
        self::assertSame([], $result->usedComponents);
        self::assertSame([], $result->warnings);
        self::assertFalse($result->hasWarnings());
    }

    #[Test]
    public function create_default_returns_runnable_converter(): void
    {
        $converter = AmpConverter::createDefault();

        $result = $converter->convert('<html><body>hi</body></html>', '/site');

        self::assertSame('<html><body>hi</body></html>', $result->html);
    }

    #[Test]
    public function create_default_roundtrips_dynamics_through_mask_unmask(): void
    {
        $converter = AmpConverter::createDefault();

        $input = '<p>{{ name }}<?php echo 1; ?></p>';
        $result = $converter->convert($input, '/site');

        self::assertSame($input, $result->html);
    }

    /**
     * @param ArrayObject<int, string> $calls
     */
    private function recordingTransformer(string $tag, ArrayObject $calls): Transformer
    {
        return new class($tag, $calls) implements Transformer {
            /** @param ArrayObject<int, string> $calls */
            public function __construct(private string $tag, private ArrayObject $calls) {}
            public function apply(string $html, Context $context): string
            {
                $this->calls[] = $this->tag;
                return $html;
            }
        };
    }

    private function upperTransformer(): Transformer
    {
        return new class implements Transformer {
            public function apply(string $html, Context $context): string
            {
                return strtoupper($html);
            }
        };
    }

    private function wrapTransformer(string $left): Transformer
    {
        return new class($left) implements Transformer {
            public function __construct(private string $left) {}
            public function apply(string $html, Context $context): string
            {
                $right = $this->left === '(' ? ')' : ']';
                return $this->left . $html . $right;
            }
        };
    }

    /**
     * @param list<string> $components
     */
    private function componentMarkingTransformer(array $components): Transformer
    {
        return new class($components) implements Transformer {
            /** @param list<string> $components */
            public function __construct(private array $components) {}
            public function apply(string $html, Context $context): string
            {
                foreach ($this->components as $c) {
                    $context->markComponentUsed($c);
                }
                return $html;
            }
        };
    }

    private function warningTransformer(string $message): Transformer
    {
        return new class($message) implements Transformer {
            public function __construct(private string $message) {}
            public function apply(string $html, Context $context): string
            {
                $context->addWarning($this->message);
                return $html;
            }
        };
    }

    /**
     * @param ArrayObject<string, ?string> $tracker
     */
    private function siteRootCapturingTransformer(ArrayObject $tracker): Transformer
    {
        return new class($tracker) implements Transformer {
            /** @param ArrayObject<string, ?string> $tracker */
            public function __construct(private ArrayObject $tracker) {}
            public function apply(string $html, Context $context): string
            {
                $this->tracker['siteRoot'] = $context->siteRoot;
                return $html;
            }
        };
    }
}
