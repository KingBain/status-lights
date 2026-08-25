<?php

declare(strict_types=1);

namespace StatusLights;

interface WorkflowStatusProvider
{
    public function fetchState(string $owner, string $repository, string $workflow): string;
}

