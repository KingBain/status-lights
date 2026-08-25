<?php

declare(strict_types=1);

namespace StatusLights;

final class WorkflowState
{
    public const SUCCESS = 'success';
    public const FAILURE = 'failure';
    public const RUNNING = 'running';
    public const UNKNOWN = 'unknown';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::SUCCESS, self::FAILURE, self::RUNNING, self::UNKNOWN];
    }

    public static function label(string $state): string
    {
        return match ($state) {
            self::SUCCESS => 'Success',
            self::FAILURE => 'Failure',
            self::RUNNING => 'Running',
            default => 'Unknown',
        };
    }
}

