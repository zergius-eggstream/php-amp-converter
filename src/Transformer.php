<?php

declare(strict_types=1);

namespace ZergiusEggstream\AmpConverter;

interface Transformer
{
    public function apply(string $html, Context $context): string;
}
