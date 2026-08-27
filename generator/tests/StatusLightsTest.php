<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

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
    public bool $filePutSucceeds = true;
    public bool $renameSucceeds = true;
    public bool $unlinkSucceeds = true;
    public bool $chmodSucceeds = true;
    public bool $tempnamSucceeds = true;
    public string $createAtomicMode = 'default';
    public bool $extensionIsLoaded = true;
    public ?Throwable $getenvException = null;
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

final class StatusLightsTest extends TestCase
{
    private MockSystem $mock;

    protected function setUp(): void
    {
        $this->mock = new MockSystem();
        $this->mock->env = [
            'STATUS_LIGHTS_APP_STORE_DIR' => '/data',
            'STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET' => 'secret',
        ];
        $this->mock->dirs[] = '/data';
    }

    private function config(): array
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

    private function request(?string $job = null): LightRequest
    {
        return new LightRequest('owner', 'repo', 'ci.yml', $job, 40, null, 'sans', 16, 6, '', [
            'success' => '1a7f37',
            'failure' => 'cf222e',
            'running' => 'bf8700',
            'unknown' => '6e7781',
        ]);
    }

    private function signedServer(string $event, string $deliveryId, string $body): array
    {
        return [
            'REQUEST_URI' => '/webhooks/github',
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_GITHUB_DELIVERY' => $deliveryId,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'secret'),
        ];
    }

    private function assertRouteError(string $uri, int $statusCode = 400): void
    {
        try {
            status_lights_parse_request($uri);
            self::fail('Expected the route to be rejected.');
        } catch (StatusLightsRouteException $exception) {
            self::assertSame($statusCode, $exception->statusCode);
        }
    }

    public function testStateHelpersAndConfiguration(): void
    {
        $this->assertSame('Success', StatusLightState::Success->label());
        $this->assertSame('Failure', StatusLightState::Failure->label());
        $this->assertSame('Running', StatusLightState::Running->label());
        $this->assertSame('Unknown', StatusLightState::Unknown->label());
        $this->assertSame('1a7f37', StatusLightState::Success->defaultColor());
        $this->assertSame('cf222e', StatusLightState::Failure->defaultColor());
        $this->assertSame('bf8700', StatusLightState::Running->defaultColor());
        $this->assertSame('6e7781', StatusLightState::Unknown->defaultColor());

        $this->mock->env += [
            'STATUS_LIGHTS_GITHUB_TOKEN' => '',
            'GITHUB_TOKEN' => 'fallback-token',
            'STATUS_LIGHTS_CACHE_TTL' => '5',
            'STATUS_LIGHTS_STALE_TTL' => '999999',
            'STATUS_LIGHTS_HTTP_CACHE_TTL' => 'invalid',
            'STATUS_LIGHTS_GITHUB_TIMEOUT' => '7',
        ];
        $config = status_lights_config($this->mock);
        $this->assertSame('fallback-token', $config['github_token']);
        $this->assertSame(10, $config['cache_ttl']);
        $this->assertSame(86400, $config['stale_ttl']);
        $this->assertSame(60, $config['http_cache_ttl']);
        $this->assertSame(7, $config['github_timeout']);
        $this->assertSame('/data', status_lights_app_store_dir($this->mock));
        $this->assertSame('secret', status_lights_app_webhook_secret($this->mock));
        $this->assertSame(1048576, status_lights_app_max_webhook_bytes($this->mock));

        $this->assertSame(StatusLightState::Running, status_lights_map_run_state(['status' => 'in_progress']));
        $this->assertSame(StatusLightState::Success, status_lights_map_run_state(['status' => 'completed', 'conclusion' => 'success']));
        $this->assertSame(StatusLightState::Failure, status_lights_map_run_state(['status' => 'completed', 'conclusion' => 'cancelled']));
        $this->assertSame(StatusLightState::Unknown, status_lights_map_run_state(['status' => 'completed', 'conclusion' => 'skipped']));
    }

    public function testParsesAndRendersConfiguredRequest(): void
    {
        $request = status_lights_parse_request('/github/Owner/@.github/ci.yml/job/Build%20Test/size/40/width/120/font/mono/font-size/14/radius/5/text/Run%20{status}/success-color/abcdef.svg');

        $this->assertSame('.github', $request->repository);
        $this->assertSame('Build Test', $request->job);
        $this->assertSame(120, $request->width);
        $this->assertSame('mono', $request->font);
        $this->assertSame('abcdef', $request->color(StatusLightState::Success));

        $svg = status_lights_render_svg($request, ['state' => StatusLightState::Success, 'cache_status' => 'miss', 'fetched_at' => 1000]);
        $this->assertStringContainsString('Run Success', $svg);
        $this->assertStringContainsString('width="120"', $svg);
        $this->assertStringContainsString('fill="#000000"', $svg);
        $this->assertSame(40, status_lights_automatic_width('', 40, 16));
        $this->assertGreaterThan(40, status_lights_automatic_width('éé', 40, 16));
        $this->assertSame('#ffffff', status_lights_contrast_color('000000'));
        $this->assertSame('#000000', status_lights_contrast_color('ffffff'));
        $this->assertSame('&lt;tag&gt;&amp;&quot;', status_lights_escape('<tag>&"'));
        $this->assertStringNotContainsString('<text ', status_lights_render_svg($this->request(), ['state' => StatusLightState::Unknown, 'cache_status' => 'miss', 'fetched_at' => 1000]));
        $this->assertStringContainsString('&lt;error&gt;', status_lights_render_error('<error>'));
    }

    public function testRejectsInvalidRoutesAndOptions(): void
    {
        $this->assertRouteError('/');
        $this->assertRouteError('/github/owner/repo/ci.yml', 404);
        $this->assertRouteError('/github/owner/repo/ci.yml/unknown/value.svg');
        $this->assertRouteError('/github/owner/repo/ci.yml/size.svg');
        $this->assertRouteError('/github/owner/repo/ci.yml/size/5.svg');
        $this->assertRouteError('/github/owner/repo/ci.yml/font/fancy.svg');
        $this->assertRouteError('/github/owner/repo/ci.yml/job/.svg');
        $this->assertRouteError('/github/owner/repo/ci.yml/text/%00.svg');
        $this->assertRouteError('/github/owner/repo/ci.yml/success-color/abc.svg');
        $this->assertRouteError('/github/owner/repo/ci.yml/size/40/size/41.svg');
        $this->assertRouteError('/github/owner/../ci.yml.svg');
        $this->assertRouteError('/github/-owner/repo/ci.yml.svg');
        $this->assertRouteError('/github/owner/repo/ci.yml/job/' . rawurlencode(str_repeat('x', 101)) . '.svg');
        $this->assertRouteError('/github/owner/repo/ci.yml/text/' . rawurlencode(str_repeat('x', 81)) . '.svg');
        $this->assertRouteError('/github/owner/repo/' . str_repeat('x', 2049) . '.svg');
    }

    public function testCreatesSvgAndJsonResponses(): void
    {
        $result = ['state' => StatusLightState::Success, 'cache_status' => 'webhook', 'fetched_at' => 1000];
        $response = status_lights_create_svg_response('<svg/>', 200, 60, $result);
        $this->assertSame(200, $response->statusCode);
        $this->assertSame('success', $response->headers['X-Status-Lights-State']);
        $this->assertSame('webhook', $response->headers['X-Status-Lights-Cache']);

        $notModified = status_lights_create_svg_response('<svg/>', 200, 60, $result, ['HTTP_IF_NONE_MATCH' => $response->headers['ETag']]);
        $this->assertSame(304, $notModified->statusCode);
        $this->assertSame('', $notModified->body);

        $json = status_lights_create_json_response(['status' => 'ok'], 201, ['X-Test' => 'yes']);
        $this->assertSame(201, $json->statusCode);
        $this->assertSame('yes', $json->headers['X-Test']);
        $this->assertSame('{"status":"ok"}', $json->body);
    }

    public function testCacheReadWriteAndResolutionStates(): void
    {
        $key = 'owner/repo/ci.yml';
        $this->assertNull(status_lights_read_cache($this->mock, '/cache', $key));
        $path = status_lights_cache_path('/cache', $key);
        $this->mock->files[$path] = '{';
        $this->assertNull(status_lights_read_cache($this->mock, '/cache', $key));
        $this->mock->files[$path] = '{"state":"not-a-state","fetched_at":1}';
        $this->assertNull(status_lights_read_cache($this->mock, '/cache', $key));

        status_lights_write_cache($this->mock, '/cache', $key, StatusLightState::Success, 1000);
        $cached = status_lights_read_cache($this->mock, '/cache', $key);
        $this->assertSame(StatusLightState::Success, $cached['state']);
        $this->assertSame(1000, $cached['fetched_at']);
        $this->assertSame(0644, $this->mock->perms[$path]);

        $request = $this->request();
        $hit = status_lights_resolve_state($this->mock, $request, $this->config(), static fn (): StatusLightState => StatusLightState::Failure, 1010);
        $this->assertSame('hit', $hit['cache_status']);
        $this->assertSame(StatusLightState::Success, $hit['state']);

        $miss = status_lights_resolve_state($this->mock, $this->request('Build'), $this->config(), static fn (): StatusLightState => StatusLightState::Failure, 1010);
        $this->assertSame('miss', $miss['cache_status']);
        $this->assertSame(StatusLightState::Failure, $miss['state']);

        $stale = status_lights_resolve_state($this->mock, $request, $this->config(), static function (): StatusLightState {
            throw new RuntimeException('GitHub unavailable.');
        }, 1100);
        $this->assertSame('stale', $stale['cache_status']);

        $error = status_lights_resolve_state($this->mock, $this->request('Other'), $this->config(), static function (): StatusLightState {
            throw new RuntimeException('GitHub unavailable.');
        }, 1100);
        $this->assertSame('error', $error['cache_status']);
        $this->assertSame(StatusLightState::Unknown, $error['state']);

        $unsupported = status_lights_resolve_state($this->mock, $this->request('Unsupported'), $this->config(), static fn (): string => 'invalid', 1100);
        $this->assertSame('error', $unsupported['cache_status']);
    }

    public function testCacheWriteFailurePaths(): void
    {
        $this->mock->mkdirSucceeds = false;
        status_lights_write_cache($this->mock, '/new-cache', 'one', StatusLightState::Success, 1);

        $this->mock->mkdirSucceeds = true;
        $this->mock->tempnamSucceeds = false;
        status_lights_write_cache($this->mock, '/new-cache', 'two', StatusLightState::Success, 1);

        $this->mock->tempnamSucceeds = true;
        $this->mock->filePutSucceeds = false;
        status_lights_write_cache($this->mock, '/new-cache', 'three', StatusLightState::Success, 1);

        $this->mock->filePutSucceeds = true;
        $this->mock->renameSucceeds = false;
        status_lights_write_cache($this->mock, '/new-cache', 'four', StatusLightState::Success, 1);

        $this->assertArrayNotHasKey(status_lights_cache_path('/new-cache', 'four'), $this->mock->files);
    }

    public function testFetchesWorkflowAndJobStateWithoutNetwork(): void
    {
        $this->mock->httpResponses = [
            ['workflow_runs' => [[
                'repository' => ['default_branch' => 'main'],
                'head_branch' => 'feature',
                'status' => 'completed',
                'conclusion' => 'failure',
            ]]],
            ['workflow_runs' => [[
                'id' => 7,
                'repository' => ['default_branch' => 'main'],
                'head_branch' => 'main',
                'status' => 'completed',
                'conclusion' => 'success',
            ]]],
            ['jobs' => [['name' => 'Build', 'status' => 'completed', 'conclusion' => 'success']]],
        ];
        $config = $this->config();
        $config['github_token'] = 'token';

        $state = status_lights_fetch_state($this->mock, 'owner', 'repo', 'ci.yml', $config, 'Build');
        $this->assertSame(StatusLightState::Success, $state);
        $this->assertCount(3, $this->mock->httpRequests);
        $this->assertStringContainsString('branch=main', $this->mock->httpRequests[1]['url']);
        $this->assertContains('Authorization: Bearer token', $this->mock->httpRequests[0]['headers']);

        $this->assertSame(StatusLightState::Unknown, status_lights_find_job_state(['jobs' => []], 'Build'));
        $this->assertSame(StatusLightState::Unknown, status_lights_find_job_state(['jobs' => 'invalid'], 'Build'));
        $this->mock->httpResponses = [['workflow_runs' => []]];
        $this->assertSame(StatusLightState::Unknown, status_lights_fetch_state($this->mock, 'owner', 'repo', 'ci.yml', $this->config()));
    }

    public function testAppStoreCrudAndPruning(): void
    {
        $repoKey = status_lights_app_repo_key('OWNER', 'Repo');
        $this->assertSame($repoKey, status_lights_app_key('owner', 'repo'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $repoKey);
        $this->assertSame(1, preg_match(StatusLightsAppStoreKind::Runs->keyPattern(), '1'));
        $this->assertSame(1, preg_match(StatusLightsAppStoreKind::Repositories->keyPattern(), $repoKey));

        status_lights_app_write($this->mock, StatusLightsAppStoreKind::Repositories, $repoKey, ['installed' => true]);
        $this->assertSame(['installed' => true], status_lights_app_read($this->mock, StatusLightsAppStoreKind::Repositories, $repoKey));
        $this->assertTrue(status_lights_app_create($this->mock, StatusLightsAppStoreKind::Repositories, status_lights_app_key('new'), ['installed' => true]));
        $this->assertFalse(status_lights_app_create($this->mock, StatusLightsAppStoreKind::Repositories, status_lights_app_key('new'), ['installed' => true]));

        $this->mock->createAtomicMode = 'null';
        $this->expectException(RuntimeException::class);
        status_lights_app_create($this->mock, StatusLightsAppStoreKind::Repositories, status_lights_app_key('error'), ['installed' => true]);
    }

    public function testAppStoreFailuresAndPruning(): void
    {
        $key = status_lights_app_key('record');
        $this->expectException(InvalidArgumentException::class);
        status_lights_app_record_path($this->mock, StatusLightsAppStoreKind::Repositories, 'not-valid');
    }

    public function testAppStoreErrorHandlingAndPruningOperations(): void
    {
        $key = status_lights_app_key('record');
        $this->mock->tempnamSucceeds = false;
        try {
            status_lights_app_write($this->mock, StatusLightsAppStoreKind::Repositories, $key, ['value' => 'x']);
            self::fail('Expected temporary file creation to fail.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $this->mock->tempnamSucceeds = true;
        $this->mock->filePutSucceeds = false;
        try {
            status_lights_app_write($this->mock, StatusLightsAppStoreKind::Repositories, $key, ['value' => 'x']);
            self::fail('Expected write to fail.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $this->mock->filePutSucceeds = true;
        $this->mock->renameSucceeds = false;
        try {
            status_lights_app_write($this->mock, StatusLightsAppStoreKind::Repositories, $key, ['value' => 'x']);
            self::fail('Expected rename to fail.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $this->mock->renameSucceeds = true;
        $this->mock->mkdirSucceeds = false;
        try {
            status_lights_app_ensure_store_directory($this->mock, StatusLightsAppStoreKind::Statuses);
            self::fail('Expected directory creation to fail.');
        } catch (RuntimeException) {
            self::assertTrue(true);
        }

        $this->mock->mkdirSucceeds = true;
        $this->assertNull(status_lights_app_read($this->mock, StatusLightsAppStoreKind::Statuses, status_lights_app_key('missing')));
        $badPath = status_lights_app_record_path($this->mock, StatusLightsAppStoreKind::Statuses, status_lights_app_key('bad'));
        $this->mock->files[$badPath] = '{';
        $this->assertNull(status_lights_app_read($this->mock, StatusLightsAppStoreKind::Statuses, status_lights_app_key('bad')));
        $this->mock->files[$badPath] = '"not-array"';
        $this->assertNull(status_lights_app_read($this->mock, StatusLightsAppStoreKind::Statuses, status_lights_app_key('bad')));

        $delivery = status_lights_app_delivery_key('72d3162e-cc78-11e3-81ab-4c9367dc0958');
        $directory = status_lights_app_store_kind_directory($this->mock, StatusLightsAppStoreKind::Deliveries);
        $this->mock->dirs[] = $directory;
        $oldPath = $directory . '/' . $delivery . '.json';
        $newPath = $directory . '/' . status_lights_app_key('new-delivery') . '.json';
        $this->mock->files[$oldPath] = '{}';
        $this->mock->files[$newPath] = '{}';
        $this->mock->files[$directory . '/invalid.json'] = '{}';
        $this->mock->mtimes[$oldPath] = 999;
        $this->mock->mtimes[$newPath] = 1000;
        $this->assertSame(1, status_lights_app_prune_deliveries_older_than(1000, $this->mock));
        $this->assertSame(0, status_lights_app_prune_runs_older_than(1000, $this->mock));

        $this->expectException(InvalidArgumentException::class);
        status_lights_app_prune_records_older_than(StatusLightsAppStoreKind::Statuses, 1000, $this->mock);
    }

    public function testValidatesDeliveryIdsAndDeletesRecords(): void
    {
        $this->assertSame('72d3162e-cc78-11e3-81ab-4c9367dc0958', status_lights_app_normalize_delivery_id('72D3162E-CC78-11E3-81AB-4C9367DC0958'));
        $this->assertNull(status_lights_app_normalize_delivery_id('bad'));
        $this->expectException(InvalidArgumentException::class);
        status_lights_app_delivery_key('bad');
    }

    public function testWebhookValidationAndOversizePaths(): void
    {
        $this->assertSame(405, status_lights_app_handle_webhook($this->mock, [])->statusCode);

        $this->mock->input = '';
        $this->assertSame(400, status_lights_app_handle_webhook($this->mock, ['REQUEST_METHOD' => 'POST'])->statusCode);

        $this->mock->input = str_repeat('a', 1048577);
        $this->assertSame(413, status_lights_app_handle_webhook($this->mock, ['REQUEST_METHOD' => 'POST'])->statusCode);

        $body = '{';
        $this->mock->input = $body;
        $this->assertSame(400, status_lights_app_handle_webhook($this->mock, $this->signedServer('workflow_run', '72d3162e-cc78-11e3-81ab-4c9367dc0958', $body))->statusCode);

        $body = json_encode('not-an-object', JSON_THROW_ON_ERROR);
        $this->mock->input = $body;
        $this->assertSame(400, status_lights_app_handle_webhook($this->mock, $this->signedServer('workflow_run', '72d3162e-cc78-11e3-81ab-4c9367dc0959', $body))->statusCode);

        $body = '{}';
        $this->mock->input = $body;
        $server = $this->signedServer('INVALID', '72d3162e-cc78-11e3-81ab-4c9367dc0960', $body);
        $this->assertSame(400, status_lights_app_handle_webhook($this->mock, $server)->statusCode);
        $server = $this->signedServer('ping', 'bad', $body);
        $this->assertSame(400, status_lights_app_handle_webhook($this->mock, $server)->statusCode);
        $server = $this->signedServer('ping', '72d3162e-cc78-11e3-81ab-4c9367dc0961', $body);
        $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256=invalid';
        $this->assertSame(401, status_lights_app_handle_webhook($this->mock, $server)->statusCode);
    }

    public function testWebhookDeliveryLifecycleAndEvents(): void
    {
        $payload = json_encode([
            'repository' => [
                'owner' => ['login' => 'owner'],
                'name' => 'repo',
                'default_branch' => 'main',
            ],
            'workflow_run' => [
                'id' => 1,
                'path' => '.github/workflows/ci.yml',
                'head_branch' => 'main',
                'status' => 'completed',
                'conclusion' => 'success',
            ],
            'installation' => ['id' => 123],
        ], JSON_THROW_ON_ERROR);
        $this->mock->input = $payload;
        $delivery = '72d3162e-cc78-11e3-81ab-4c9367dc0962';
        $response = status_lights_app_handle_webhook($this->mock, $this->signedServer('workflow_run', $delivery, $payload));
        $this->assertSame(202, $response->statusCode);
        $statusKey = status_lights_app_status_key('owner', 'repo', 'ci.yml');
        $this->assertArrayHasKey('/data/statuses/' . $statusKey . '.json', $this->mock->files);

        $this->mock->input = $payload;
        $duplicate = status_lights_app_handle_webhook($this->mock, $this->signedServer('workflow_run', $delivery, $payload));
        $this->assertStringContainsString('"duplicate":true', $duplicate->body);

        $jobPayload = json_encode([
            'repository' => ['owner' => ['login' => 'owner'], 'name' => 'repo', 'default_branch' => 'main'],
            'workflow_job' => ['run_id' => 1, 'name' => 'Build', 'head_branch' => 'main', 'status' => 'completed', 'conclusion' => 'failure'],
        ], JSON_THROW_ON_ERROR);
        $this->mock->input = $jobPayload;
        $this->assertSame(202, status_lights_app_handle_webhook($this->mock, $this->signedServer('workflow_job', '72d3162e-cc78-11e3-81ab-4c9367dc0963', $jobPayload))->statusCode);
        $jobKey = status_lights_app_status_key('owner', 'repo', 'ci.yml', 'Build');
        $this->assertArrayHasKey('/data/statuses/' . $jobKey . '.json', $this->mock->files);

        $ping = '{}';
        $this->mock->input = $ping;
        $this->assertSame(200, status_lights_app_handle_webhook($this->mock, $this->signedServer('ping', '72d3162e-cc78-11e3-81ab-4c9367dc0964', $ping))->statusCode);
    }

    public function testInstallationAndWebhookFailureHandling(): void
    {
        $repository = ['owner' => ['login' => 'owner'], 'name' => 'repo', 'default_branch' => 'main'];
        status_lights_app_handle_installation($this->mock, 'installation', ['action' => 'created', 'installation' => ['id' => 1], 'repositories' => [$repository]]);
        $record = status_lights_app_read($this->mock, StatusLightsAppStoreKind::Repositories, status_lights_app_repo_key('owner', 'repo'));
        $this->assertTrue($record['installed']);

        status_lights_app_handle_installation($this->mock, 'installation_repositories', ['installation' => ['id' => 1], 'repositories_removed' => [$repository]]);
        $record = status_lights_app_read($this->mock, StatusLightsAppStoreKind::Repositories, status_lights_app_repo_key('owner', 'repo'));
        $this->assertFalse($record['installed']);

        $payload = json_encode([
            'repository' => $repository,
            'workflow_run' => ['id' => 9, 'path' => 'ci.yml', 'head_branch' => 'main', 'status' => 'completed', 'conclusion' => 'success'],
        ], JSON_THROW_ON_ERROR);
        $this->mock->filePutSucceeds = false;
        $this->mock->input = $payload;
        $response = status_lights_app_handle_webhook($this->mock, $this->signedServer('workflow_run', '72d3162e-cc78-11e3-81ab-4c9367dc0965', $payload));
        $this->assertSame(500, $response->statusCode);
    }

    public function testAppResolutionAndEndpoints(): void
    {
        $this->assertSame(302, status_lights_app_handle_request($this->mock, ['REQUEST_URI' => '/'])->statusCode);
        $this->assertSame(200, status_lights_app_handle_request($this->mock, ['REQUEST_URI' => '/health'])->statusCode);

        $this->mock->env['STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET'] = '';
        $this->assertSame(503, status_lights_app_handle_request($this->mock, ['REQUEST_URI' => '/health'])->statusCode);
        $this->mock->env['STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET'] = 'secret';

        $unknown = status_lights_app_handle_request($this->mock, ['REQUEST_URI' => '/github/owner/repo/ci.yml.svg']);
        $this->assertStringContainsString('data-state="unknown"', $unknown->body);
        $this->assertSame('not-installed', $unknown->headers['X-Status-Lights-Cache']);

        status_lights_app_mark_repository($this->mock, ['owner' => ['login' => 'owner'], 'name' => 'repo'], 1, true);
        $empty = status_lights_app_handle_request($this->mock, ['REQUEST_URI' => '/github/owner/repo/ci.yml.svg']);
        $this->assertSame('app-empty', $empty->headers['X-Status-Lights-Cache']);

        $statusKey = status_lights_app_status_key('owner', 'repo', 'ci.yml');
        status_lights_app_write($this->mock, StatusLightsAppStoreKind::Statuses, $statusKey, ['state' => 'success', 'updated_at' => 900]);
        $success = status_lights_app_handle_request($this->mock, ['REQUEST_URI' => '/github/owner/repo/ci.yml.svg']);
        $this->assertSame('webhook', $success->headers['X-Status-Lights-Cache']);
        $this->assertSame('success', $success->headers['X-Status-Lights-State']);
        $notModified = status_lights_app_handle_request($this->mock, ['REQUEST_URI' => '/github/owner/repo/ci.yml.svg', 'HTTP_IF_NONE_MATCH' => $success->headers['ETag']]);
        $this->assertSame(304, $notModified->statusCode);

        $this->assertSame(404, status_lights_app_handle_request($this->mock, ['REQUEST_URI' => '/not-a-route'])->statusCode);
        $this->mock->getenvException = new RuntimeException('environment unavailable');
        $this->assertSame(500, status_lights_app_handle_request($this->mock, ['REQUEST_URI' => '/github/owner/repo/ci.yml.svg'])->statusCode);
    }

    public function testLegacyEndpointAndRealSystemFileAdapter(): void
    {
        $this->assertSame(302, status_lights_handle_legacy_request($this->mock, ['REQUEST_URI' => '/'])->statusCode);
        $this->mock->extensionIsLoaded = false;
        $this->assertSame(503, status_lights_handle_legacy_request($this->mock, ['REQUEST_URI' => '/health'])->statusCode);
        $this->mock->extensionIsLoaded = true;
        $this->assertSame(404, status_lights_handle_legacy_request($this->mock, ['REQUEST_URI' => '/wrong'])->statusCode);

        $directory = sys_get_temp_dir() . '/status-lights-' . bin2hex(random_bytes(6));
        $real = new StatusLightsRealSystem();
        $this->assertSame('', $real->getenv('STATUS_LIGHTS_TEST_MISSING_' . bin2hex(random_bytes(4))));
        $this->assertIsInt($real->time());
        $this->assertIsString($real->readInput(1));
        $this->assertTrue($real->mkdir($directory, 0755, true));
        $this->assertTrue($real->isDir($directory));
        $file = $directory . '/one.txt';
        $this->assertSame(3, $real->filePutContents($file, 'one'));
        $this->assertTrue($real->isFile($file));
        $this->assertTrue($real->isWritable($file));
        $this->assertSame('one', $real->fileGetContents($file));
        $this->assertTrue($real->chmod($file, 0644));
        $temporary = $real->tempnam($directory, 'temp-');
        $this->assertIsString($temporary);
        $this->assertTrue($real->rename($file, $directory . '/two.txt'));
        $this->assertIsInt($real->filemtime($directory . '/two.txt'));
        $this->assertTrue($real->createAtomicFile($directory . '/atomic.txt', 'atomic'));
        $this->assertFalse($real->createAtomicFile($directory . '/atomic.txt', 'again'));
        $this->assertNull($real->createAtomicFile($directory . '/missing/atomic.txt', 'nope'));
        $this->assertSame([], iterator_to_array($real->getJsonFilesInDirectory($directory . '/missing')));
        $this->assertSame(2, $real->filePutContents($directory . '/data.json', '{}'));
        $files = iterator_to_array($real->getJsonFilesInDirectory($directory));
        $this->assertArrayHasKey($directory . '/data.json', $files);
        $this->assertTrue($real->extensionLoaded('json'));
        $this->assertTrue($real->unlink($temporary));
        $this->assertTrue($real->unlink($directory . '/two.txt'));
        $this->assertTrue($real->unlink($directory . '/atomic.txt'));
        $this->assertTrue($real->unlink($directory . '/data.json'));
        $this->assertTrue(rmdir($directory));
    }
}
