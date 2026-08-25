<?php

declare(strict_types=1);

namespace StatusLights;

final readonly class StatusResult
{
    public function __construct(
        public string $state,
        public string $cacheStatus,
        public int $fetchedAt,
    ) {
        if (!in_array($state, WorkflowState::all(), true)) {
            throw new \InvalidArgumentException('Unsupported workflow state.');
        }
    }
}

