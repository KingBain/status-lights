<?php

declare(strict_types=1);

namespace StatusLights;

final class InvalidRoute extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 400)
    {
        parent::__construct($message);
    }
}

