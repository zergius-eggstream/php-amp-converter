<?php

declare(strict_types=1);

namespace ZergiusEggstream\AmpConverter;

final readonly class ConversionResult
{
    /**
     * @param list<string> $usedComponents  AMP custom-element names used in output
     * @param list<string> $warnings        non-fatal issues encountered during conversion
     */
    public function __construct(
        public string $html,
        public array $usedComponents,
        public array $warnings,
    ) {}

    public function hasWarnings(): bool
    {
        return $this->warnings !== [];
    }
}
