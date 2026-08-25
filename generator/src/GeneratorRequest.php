<?php

declare(strict_types=1);

namespace StatusLights;

final readonly class GeneratorRequest
{
    /** @param array<string, string> $colors */
    public function __construct(
        public string $owner,
        public string $repository,
        public string $workflow,
        public int $height,
        public ?int $width,
        public string $font,
        public int $fontSize,
        public int $radius,
        public string $text,
        public array $colors,
    ) {
    }
}

