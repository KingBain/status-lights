<?php

declare(strict_types=1);

namespace StatusLights;

final class FileCache
{
    public function __construct(private readonly string $directory)
    {
    }

    /** @return array{state: string, fetched_at: int}|null */
    public function read(string $key): ?array
    {
        $path = $this->path($key);

        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if (!is_string($contents)) {
            return null;
        }

        try {
            $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (
            !is_array($value)
            || !isset($value['state'], $value['fetched_at'])
            || !in_array($value['state'], WorkflowState::all(), true)
            || !is_int($value['fetched_at'])
        ) {
            return null;
        }

        return ['state' => $value['state'], 'fetched_at' => $value['fetched_at']];
    }

    public function write(string $key, string $state, int $fetchedAt): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return;
        }

        try {
            $contents = json_encode(
                ['state' => $state, 'fetched_at' => $fetchedAt],
                JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return;
        }

        $temporaryPath = @tempnam($this->directory, 'status-light-');

        if (!is_string($temporaryPath)) {
            return;
        }

        if (@file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
            @unlink($temporaryPath);
            return;
        }

        @chmod($temporaryPath, 0664);

        if (!@rename($temporaryPath, $this->path($key))) {
            @unlink($temporaryPath);
        }
    }

    private function path(string $key): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . hash('sha256', $key)
            . '.json';
    }
}

