<?php

declare(strict_types=1);

if (!defined('STATUS_LIGHTS_APP_TESTING')) {
    define('STATUS_LIGHTS_APP_TESTING', true);
}

require_once __DIR__ . '/../app.php';

final class MockSystem implements StatusLightsSystem
{
    /** @var array<string, string> */
    public array $env = [];
    public string $input = '';
    public int $time = 1000;
    /** @var array<string, string> */
    public array $files = [];
    /** @var list<string> */
    public array $dirs = [];
    /** @var array<string, bool> */
    public array $writable = [];
    /** @var array<string, int> */
    public array $perms = [];
    /** @var array<string, int|false> */
    public array $mtimes = [];
    public bool $mkdirSucceeds = true;
    public bool $fileGetSucceeds = true;
    public bool $filePutSucceeds = true;
    public bool $renameSucceeds = true;
    public bool $unlinkSucceeds = true;
    public bool $chmodSucceeds = true;
    public bool $tempnamSucceeds = true;
    public string $createAtomicMode = 'default';
    public bool $extensionIsLoaded = true;
    public ?Throwable $getenvException = null;
    public ?Throwable $timeException = null;
    public ?Throwable $httpException = null;
    /** @var list<array<string, mixed>> */
    public array $httpResponses = [];
    /** @var list<array{url: string, headers: list<string>, timeout: int}> */
    public array $httpRequests = [];
    private int $temporaryNumber = 0;

    public function getenv(string $name): string
    {
        if ($this->getenvException !== null) {
            throw $this->getenvException;
        }
        return $this->env[$name] ?? '';
    }

    public function time(): int
    {
        if ($this->timeException !== null) {
            throw $this->timeException;
        }
        return $this->time;
    }

    public function readInput(int $maxBytes): string
    {
        return substr($this->input, 0, $maxBytes + 1);
    }

    public function isDir(string $path): bool
    {
        return in_array($path, $this->dirs, true);
    }

    public function isFile(string $path): bool
    {
        return array_key_exists($path, $this->files);
    }

    public function isWritable(string $path): bool
    {
        return $this->writable[$path] ?? true;
    }

    public function mkdir(string $path, int $permissions, bool $recursive): bool
    {
        if (!$this->mkdirSucceeds) {
            return false;
        }
        if (!$this->isDir($path)) {
            $this->dirs[] = $path;
        }
        return true;
    }

    public function fileGetContents(string $path): string|false
    {
        if (!$this->fileGetSucceeds) {
            return false;
        }
        return $this->files[$path] ?? false;
    }

    public function filePutContents(string $path, string $data, int $flags = 0): int|false
    {
        if (!$this->filePutSucceeds) {
            return false;
        }
        $this->files[$path] = $data;
        return strlen($data);
    }

    public function rename(string $from, string $to): bool
    {
        if (!$this->renameSucceeds || !array_key_exists($from, $this->files)) {
            return false;
        }
        $this->files[$to] = $this->files[$from];
        unset($this->files[$from]);
        return true;
    }

    public function unlink(string $path): bool
    {
        if (!$this->unlinkSucceeds) {
            return false;
        }
        unset($this->files[$path]);
        return true;
    }

    public function chmod(string $path, int $permissions): bool
    {
        if (!$this->chmodSucceeds) {
            return false;
        }
        $this->perms[$path] = $permissions;
        return true;
    }

    public function tempnam(string $dir, string $prefix): string|false
    {
        if (!$this->tempnamSucceeds) {
            return false;
        }
        $this->temporaryNumber++;
        return $dir . '/' . $prefix . $this->temporaryNumber;
    }

    public function filemtime(string $path): int|false
    {
        return $this->mtimes[$path] ?? $this->time;
    }

    public function createAtomicFile(string $path, string $contents): ?bool
    {
        if ($this->createAtomicMode === 'null') {
            return null;
        }
        if ($this->createAtomicMode === 'false' || array_key_exists($path, $this->files)) {
            return false;
        }
        if ($this->createAtomicMode === 'throw') {
            throw new RuntimeException('Atomic write failed.');
        }
        $this->files[$path] = $contents;
        return true;
    }

    /** @return iterable<string, string> */
    public function getJsonFilesInDirectory(string $path): iterable
    {
        foreach ($this->files as $filePath => $contents) {
            if (str_starts_with($filePath, $path . '/') && str_ends_with($filePath, '.json')) {
                yield $filePath => basename($filePath, '.json');
            }
        }
    }

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    public function fetchHttpJson(string $url, array $headers, int $timeout): array
    {
        $this->httpRequests[] = ['url' => $url, 'headers' => $headers, 'timeout' => $timeout];
        if ($this->httpException !== null) {
            throw $this->httpException;
        }
        return array_shift($this->httpResponses) ?? ['workflow_runs' => []];
    }

    public function extensionLoaded(string $name): bool
    {
        return $this->extensionIsLoaded;
    }
}

/** @return array<string, int|string|null> */
function status_lights_test_config(): array
{
    return [
        'cache_directory' => '/cache',
        'cache_ttl' => 60,
        'stale_ttl' => 3600,
        'http_cache_ttl' => 60,
        'github_timeout' => 5,
        'github_token' => null,
    ];
}

function status_lights_test_system(): MockSystem
{
    $mock = new MockSystem();
    $mock->env = [
        'STATUS_LIGHTS_APP_STORE_DIR' => '/data',
        'STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET' => 'secret',
    ];
    $mock->dirs[] = '/data';

    return $mock;
}

function status_lights_test_request(?string $job = null): LightRequest
{
    return new LightRequest('owner', 'repo', 'ci.yml', $job, 40, null, 'sans', 16, 6, '', [
        'success' => '1a7f37',
        'failure' => 'cf222e',
        'running' => 'bf8700',
        'unknown' => '6e7781',
    ]);
}

/** @return array<string, string> */
function status_lights_test_signed_server(string $event, string $deliveryId, string $body): array
{
    return [
        'REQUEST_URI' => '/webhooks/github',
        'REQUEST_METHOD' => 'POST',
        'HTTP_X_GITHUB_EVENT' => $event,
        'HTTP_X_GITHUB_DELIVERY' => $deliveryId,
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'secret'),
    ];
}

function status_lights_test_expect_route_error(string $uri, int $statusCode = 400): void
{
    try {
        status_lights_parse_request($uri);
    } catch (StatusLightsRouteException $exception) {
        expect($exception->statusCode)->toBe($statusCode);

        return;
    }

    throw new RuntimeException('Expected the route to be rejected.');
}
