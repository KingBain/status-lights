<?php

declare(strict_types=1);

if (!defined('STATUS_LIGHTS_TESTING')) {
    define('STATUS_LIGHTS_TESTING', true);
}
if (!defined('STATUS_LIGHTS_APP_TESTING')) {
    define('STATUS_LIGHTS_APP_TESTING', true);
}
require_once dirname(__DIR__) . '/app.php';

$tests = [];

function test(string $name, callable $test): void
{
    global $tests;
    $tests[] = [$name, $test];
}

function expect(bool $condition, string $message = 'Expectation failed.'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Expected %s, received %s.',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function expectRouteFailure(string $uri): void
{
    try {
        status_lights_parse_request($uri);
    } catch (StatusLightsRouteException) {
        return;
    }

    throw new RuntimeException('Expected route parsing to fail.');
}

/** @param class-string<Throwable> $exceptionClass */
function expectThrows(string $exceptionClass, callable $callback): void
{
    try {
        $callback();
    } catch (Throwable $exception) {
        if ($exception instanceof $exceptionClass) {
            return;
        }

        throw $exception;
    }

    throw new RuntimeException(sprintf('Expected %s to be thrown.', $exceptionClass));
}

function removeTestDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($directory);
}

function requestFixture(): LightRequest
{
    return status_lights_parse_request('/github/KingBain/status-lights/pages.yml.svg');
}

test('parses the canonical route with defaults', static function (): void {
    $request = requestFixture();

    expectSame('KingBain', $request->owner);
    expectSame('status-lights', $request->repository);
    expectSame('pages.yml', $request->workflow);
    expectSame(null, $request->job);
    expectSame(40, $request->height);
    expectSame(null, $request->width);
    expectSame('', $request->text);
});

test('parses an escaped dot-prefixed repository route', static function (): void {
    $request = status_lights_parse_request(
        '/github/ssc-sp/@.github/manage-org-members.yml.svg',
    );

    expectSame('ssc-sp', $request->owner);
    expectSame('.github', $request->repository);
    expectSame('manage-org-members.yml', $request->workflow);
});

test('parses a job route and appearance options', static function (): void {
    $request = status_lights_parse_request(
        '/github/KingBain/status-lights/pages.yml/job/Validate%20site/size/32'
        . '/text/Validate%3A%20%7Bstatus%7D.svg',
    );

    expectSame('pages.yml', $request->workflow);
    expectSame('Validate site', $request->job);
    expectSame(32, $request->height);
    expectSame('Validate: {status}', $request->text);
});

test('decodes a double-encoded slash inside a job name', static function (): void {
    $request = status_lights_parse_request(
        '/github/KingBain/status-lights/pages.yml/job/Build%252Fdeploy.svg',
    );

    expectSame('Build/deploy', $request->job);
});

test('parses every URL emitted by the browser builder', static function (): void {
    $request = status_lights_parse_request(
        '/github/KingBain/status-lights/pages.yml/size/48/font/mono/font-size/18/radius/8'
        . '/success-color/00aa00/failure-color/aa0000/running-color/ffaa00/unknown-color/777777'
        . '/text/Build%3A%20%7Bstatus%7D.svg',
    );

    expectSame(48, $request->height);
    expectSame('mono', $request->font);
    expectSame(18, $request->fontSize);
    expectSame('Build: {status}', $request->text);
    expectSame('00aa00', $request->color(StatusLightState::Success));
});

test('decodes a double-encoded slash inside text', static function (): void {
    $request = status_lights_parse_request(
        '/github/KingBain/status-lights/pages.yml/text/Build%252FDeploy.svg',
    );

    expectSame('Build/Deploy', $request->text);
});

test('rejects unsafe or unsupported route options', static function (): void {
    expectRouteFailure('/github/owner/repository/workflow.yml/size/500.svg');
    expectRouteFailure('/github/owner/repository/workflow.yml/surprise/value.svg');
    expectRouteFailure('/github/owner/repository/workflow.yml/text/%00.svg');
    expectRouteFailure('/github/owner/repository/workflow.yml/job/.svg');
    expectRouteFailure('/github/owner/repository/workflow.yml/job/%00.svg');
});

test('rejects canonical dot segments in route identifiers', static function (): void {
    expectRouteFailure('/github/%2e/repository/workflow.yml.svg');
    expectRouteFailure('/github/%2e%2e/repository/workflow.yml.svg');
    expectRouteFailure('/github/owner/%2e/workflow.yml.svg');
    expectRouteFailure('/github/owner/%2e%2e/workflow.yml.svg');
    expectRouteFailure('/github/owner/@./workflow.yml.svg');
    expectRouteFailure('/github/owner/@../workflow.yml.svg');
    expectRouteFailure('/github/owner/@repository/workflow.yml.svg');
    expectRouteFailure('/github/owner/repository/%2e.svg');
    expectRouteFailure('/github/owner/repository/%2e%2e.svg');
    expectRouteFailure('/github/owner/repository/%252e%252e.svg');
});

test('finds an individual GitHub Actions job by display name', static function (): void {
    $payload = [
        'jobs' => [
            ['name' => 'Validate site', 'status' => 'completed', 'conclusion' => 'success'],
            ['name' => 'Deploy site', 'status' => 'in_progress', 'conclusion' => null],
        ],
    ];

    expectSame(
        StatusLightState::Success,
        status_lights_find_job_state($payload, 'Validate site'),
    );
    expectSame(
        StatusLightState::Running,
        status_lights_find_job_state($payload, 'Deploy site'),
    );
    expectSame(
        StatusLightState::Unknown,
        status_lights_find_job_state($payload, 'Missing job'),
    );
});

test('maps GitHub workflow runs to stable states', static function (): void {
    expectSame(
        StatusLightState::Running,
        status_lights_map_run_state(['status' => 'in_progress']),
    );
    expectSame(
        StatusLightState::Success,
        status_lights_map_run_state(['status' => 'completed', 'conclusion' => 'success']),
    );
    expectSame(
        StatusLightState::Failure,
        status_lights_map_run_state(['status' => 'completed', 'conclusion' => 'timed_out']),
    );
    expectSame(
        StatusLightState::Unknown,
        status_lights_map_run_state(['status' => 'completed', 'conclusion' => 'skipped']),
    );
});

test('rejects oversized webhook bodies without reading them into memory', static function (): void {
    $previousLength = $_SERVER['CONTENT_LENGTH'] ?? null;
    putenv('STATUS_LIGHTS_MAX_WEBHOOK_BYTES=65536');

    try {
        $_SERVER['CONTENT_LENGTH'] = '65537';
        expectThrows(
            StatusLightsPayloadTooLargeException::class,
            static fn (): string => status_lights_app_read_webhook_body(fopen('php://temp', 'w+b')),
        );

        unset($_SERVER['CONTENT_LENGTH']);
        $oversized = fopen('php://temp', 'w+b');
        expect(is_resource($oversized));
        fwrite($oversized, str_repeat('a', 65537));
        rewind($oversized);
        expectThrows(
            StatusLightsPayloadTooLargeException::class,
            static fn (): string => status_lights_app_read_webhook_body($oversized),
        );
        fclose($oversized);

        $maximum = fopen('php://temp', 'w+b');
        expect(is_resource($maximum));
        fwrite($maximum, str_repeat('a', 65536));
        rewind($maximum);
        expectSame(65536, strlen(status_lights_app_read_webhook_body($maximum)));
        fclose($maximum);
    } finally {
        putenv('STATUS_LIGHTS_MAX_WEBHOOK_BYTES');
        if ($previousLength === null) {
            unset($_SERVER['CONTENT_LENGTH']);
        } else {
            $_SERVER['CONTENT_LENGTH'] = $previousLength;
        }
    }
});

test('validates record kinds and keys at the storage boundary', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-app-tests-' . bin2hex(random_bytes(6));
    putenv('STATUS_LIGHTS_APP_STORE_DIR=' . $directory);
    $repositoryKey = str_repeat('a', 64);

    try {
        status_lights_app_write(
            StatusLightsAppStoreKind::Repositories,
            $repositoryKey,
            ['installed' => true],
        );

        expectSame(
            ['installed' => true],
            status_lights_app_read(StatusLightsAppStoreKind::Repositories, $repositoryKey),
        );
        expectThrows(
            InvalidArgumentException::class,
            static fn (): string => status_lights_app_record_path(
                StatusLightsAppStoreKind::Repositories,
                '../escape',
            ),
        );
        expectThrows(
            InvalidArgumentException::class,
            static fn (): string => status_lights_app_record_path(
                StatusLightsAppStoreKind::Runs,
                '1/../../2',
            ),
        );
        expectThrows(
            TypeError::class,
            static fn (): ?array => status_lights_app_read('runs', '1'),
        );
    } finally {
        putenv('STATUS_LIGHTS_APP_STORE_DIR');
        removeTestDirectory($directory);
    }
});

test('claims each GitHub webhook delivery exactly once', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-app-tests-' . bin2hex(random_bytes(6));
    putenv('STATUS_LIGHTS_APP_STORE_DIR=' . $directory);
    $deliveryId = '72d3162e-cc78-11e3-81ab-4c9367dc0958';

    try {
        expectSame($deliveryId, status_lights_app_normalize_delivery_id(strtoupper($deliveryId)));
        expect(status_lights_app_claim_delivery($deliveryId));
        expect(!status_lights_app_claim_delivery(strtoupper($deliveryId)));

        status_lights_app_release_delivery($deliveryId);
        expect(status_lights_app_claim_delivery($deliveryId));
        expectSame(null, status_lights_app_normalize_delivery_id('../not-a-guid'));
        expectThrows(
            InvalidArgumentException::class,
            static fn (): bool => status_lights_app_claim_delivery('../not-a-guid'),
        );
    } finally {
        putenv('STATUS_LIGHTS_APP_STORE_DIR');
        removeTestDirectory($directory);
    }
});

test('prunes only expired transient records', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-app-tests-' . bin2hex(random_bytes(6));
    putenv('STATUS_LIGHTS_APP_STORE_DIR=' . $directory);
    $statusKey = str_repeat('b', 64);
    $expiredDeliveryKey = status_lights_app_delivery_key(
        '72d3162e-cc78-11e3-81ab-4c9367dc0958',
    );
    $currentDeliveryKey = status_lights_app_delivery_key(
        '72d3162e-cc78-11e3-81ab-4c9367dc0959',
    );

    try {
        status_lights_app_write(StatusLightsAppStoreKind::Runs, '1001', ['updated_at' => 100]);
        status_lights_app_write(StatusLightsAppStoreKind::Runs, '1002', ['updated_at' => 200]);
        status_lights_app_write(StatusLightsAppStoreKind::Statuses, $statusKey, ['updated_at' => 100]);
        status_lights_app_write(
            StatusLightsAppStoreKind::Deliveries,
            $expiredDeliveryKey,
            ['received_at' => 100],
        );
        status_lights_app_write(
            StatusLightsAppStoreKind::Deliveries,
            $currentDeliveryKey,
            ['received_at' => 200],
        );
        touch($directory . '/runs/1001.json', 100);
        touch($directory . '/runs/1002.json', 200);
        touch($directory . '/deliveries/' . $expiredDeliveryKey . '.json', 100);
        touch($directory . '/deliveries/' . $currentDeliveryKey . '.json', 200);
        file_put_contents($directory . '/runs/keep.txt', 'not a run record');
        file_put_contents($directory . '/runs/not-a-valid-key.json', 'not a run record');
        touch($directory . '/runs/keep.txt', 100);
        touch($directory . '/runs/not-a-valid-key.json', 100);

        expectSame(1, status_lights_app_prune_runs_older_than(150));
        expectSame(1, status_lights_app_prune_deliveries_older_than(150));
        expect(!is_file($directory . '/runs/1001.json'));
        expect(is_file($directory . '/runs/1002.json'));
        expect(!is_file($directory . '/deliveries/' . $expiredDeliveryKey . '.json'));
        expect(is_file($directory . '/deliveries/' . $currentDeliveryKey . '.json'));
        expect(is_file($directory . '/runs/keep.txt'));
        expect(is_file($directory . '/runs/not-a-valid-key.json'));
        expect(is_file($directory . '/statuses/' . $statusKey . '.json'));
    } finally {
        putenv('STATUS_LIGHTS_APP_STORE_DIR');
        removeTestDirectory($directory);
    }
});

test('stores webhook state and resolves it through typed application boundaries', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-app-tests-' . bin2hex(random_bytes(6));
    putenv('STATUS_LIGHTS_APP_STORE_DIR=' . $directory);

    try {
        status_lights_app_handle_workflow_run([
            'installation' => ['id' => 123],
            'repository' => [
                'name' => 'status-lights',
                'default_branch' => 'main',
                'owner' => ['login' => 'KingBain'],
            ],
            'workflow_run' => [
                'id' => 456,
                'path' => '.github/workflows/pages.yml',
                'head_branch' => 'main',
                'status' => 'completed',
                'conclusion' => 'success',
            ],
        ]);

        $result = status_lights_app_resolve(requestFixture());
        expectSame(StatusLightState::Success, $result['state']);
        expectSame('webhook', $result['cache_status']);
    } finally {
        putenv('STATUS_LIGHTS_APP_STORE_DIR');
        removeTestDirectory($directory);
    }
});

test('renders a safe accessible SVG', static function (): void {
    $request = status_lights_parse_request(
        '/github/KingBain/status-lights/pages.yml/text/Build%3A%20%7Bstatus%7D%20%26%20safe.svg',
    );
    $svg = status_lights_render_svg($request, [
        'state' => StatusLightState::Success,
        'cache_status' => 'miss',
        'fetched_at' => 1,
    ]);

    expect(str_contains($svg, 'data-state="success"'));
    expect(str_contains($svg, 'Build: Success &amp; safe'));
    expect(!str_contains($svg, 'Build: Success & safe'));
    expect(str_contains($svg, '<title id="title">'));
});

test('uses a square SVG when no text is requested', static function (): void {
    $svg = status_lights_render_svg(requestFixture(), [
        'state' => StatusLightState::Unknown,
        'cache_status' => 'miss',
        'fetched_at' => 1,
    ]);

    expect(str_contains($svg, 'width="40" height="40"'));
    expect(!str_contains($svg, '<text'));
});

test('animates the SVG for a running state only', static function (): void {
    $result = [
        'state' => StatusLightState::Running,
        'cache_status' => 'miss',
        'fetched_at' => 1,
    ];
    $runningSvg = status_lights_render_svg(requestFixture(), $result);

    $result['state'] = StatusLightState::Success;
    $successSvg = status_lights_render_svg(requestFixture(), $result);

    expect(str_contains($runningSvg, '@keyframes status-lights-pulse'));
    expect(str_contains(
        $runningSvg,
        'rect{animation:status-lights-pulse 2s ease-in-out infinite}',
    ));
    expect(!str_contains($successSvg, '@keyframes status-lights-pulse'));
});

test('identifies a selected job in accessible SVG text', static function (): void {
    $request = status_lights_parse_request(
        '/github/KingBain/status-lights/pages.yml/job/Deploy%20site.svg',
    );
    $svg = status_lights_render_svg($request, [
        'state' => StatusLightState::Success,
        'cache_status' => 'miss',
        'fetched_at' => 1,
    ]);

    expect(str_contains($svg, 'pages.yml job Deploy site status: Success'));
});

test('caches GitHub state and falls back to stale data', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-tests-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $calls = 0;
    $fail = false;
    $provider = static function () use (&$calls, &$fail): StatusLightState {
        $calls++;

        if ($fail) {
            throw new RuntimeException('Upstream unavailable.');
        }

        return StatusLightState::Success;
    };
    $config = [
        'cache_directory' => $directory,
        'cache_ttl' => 60,
        'stale_ttl' => 3600,
        'http_cache_ttl' => 60,
        'github_timeout' => 5,
        'github_token' => null,
    ];

    $first = status_lights_resolve_state(requestFixture(), $config, $provider, 1000);
    $second = status_lights_resolve_state(requestFixture(), $config, $provider, 1030);
    $fail = true;
    $stale = status_lights_resolve_state(requestFixture(), $config, $provider, 1100);

    expectSame('miss', $first['cache_status']);
    expectSame('hit', $second['cache_status']);
    expectSame('stale', $stale['cache_status']);
    expectSame(2, $calls);
    expectSame(StatusLightState::Success, $stale['state']);

    foreach (glob($directory . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory);
});

test('preserves workflow filename case in cache keys', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-tests-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $calls = 0;
    $provider = static function (
        string $owner,
        string $repository,
        string $workflow,
    ) use (&$calls): StatusLightState {
        $calls++;

        return $workflow === 'build.yml'
            ? StatusLightState::Success
            : StatusLightState::Failure;
    };
    $config = [
        'cache_directory' => $directory,
        'cache_ttl' => 60,
        'stale_ttl' => 3600,
        'http_cache_ttl' => 60,
        'github_timeout' => 5,
        'github_token' => null,
    ];
    $lowercase = status_lights_parse_request('/github/Owner/Repository/build.yml.svg');
    $uppercase = status_lights_parse_request('/github/owner/repository/Build.yml.svg');

    $lowercaseResult = status_lights_resolve_state($lowercase, $config, $provider, 1000);
    $uppercaseResult = status_lights_resolve_state($uppercase, $config, $provider, 1000);

    expectSame(StatusLightState::Success, $lowercaseResult['state']);
    expectSame(StatusLightState::Failure, $uppercaseResult['state']);
    expectSame(2, $calls);

    foreach (glob($directory . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory);
});

test('keeps workflow and job cache entries separate', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-tests-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $calls = 0;
    $provider = static function (
        string $owner,
        string $repository,
        string $workflow,
        ?string $job,
    ) use (&$calls): StatusLightState {
        $calls++;

        return $job === null ? StatusLightState::Success : StatusLightState::Failure;
    };
    $config = [
        'cache_directory' => $directory,
        'cache_ttl' => 60,
        'stale_ttl' => 3600,
        'http_cache_ttl' => 60,
        'github_timeout' => 5,
        'github_token' => null,
    ];
    $workflow = requestFixture();
    $job = status_lights_parse_request(
        '/github/KingBain/status-lights/pages.yml/job/Validate%20site.svg',
    );

    $workflowResult = status_lights_resolve_state($workflow, $config, $provider, 1000);
    $jobResult = status_lights_resolve_state($job, $config, $provider, 1000);

    expectSame(StatusLightState::Success, $workflowResult['state']);
    expectSame(StatusLightState::Failure, $jobResult['state']);
    expectSame(2, $calls);

    foreach (glob($directory . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory);
});

test('returns unknown when the provider has no usable data', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-tests-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $config = [
        'cache_directory' => $directory,
        'cache_ttl' => 60,
        'stale_ttl' => 3600,
        'http_cache_ttl' => 60,
        'github_timeout' => 5,
        'github_token' => null,
    ];
    $provider = static function (): StatusLightState {
        throw new RuntimeException('Upstream unavailable.');
    };

    $result = status_lights_resolve_state(requestFixture(), $config, $provider, 1000);

    expectSame(StatusLightState::Unknown, $result['state']);
    expectSame('error', $result['cache_status']);
    rmdir($directory);
});

test('covers configuration and validation boundaries', static function (): void {
    $names = [
        'STATUS_LIGHTS_GITHUB_TOKEN',
        'GITHUB_TOKEN',
        'STATUS_LIGHTS_CACHE_DIR',
        'STATUS_LIGHTS_CACHE_TTL',
        'STATUS_LIGHTS_STALE_TTL',
        'STATUS_LIGHTS_HTTP_CACHE_TTL',
        'STATUS_LIGHTS_GITHUB_TIMEOUT',
    ];

    try {
        foreach ($names as $name) {
            putenv($name);
        }
        expectSame(null, status_lights_config()['github_token']);

        putenv('GITHUB_TOKEN=fallback-token');
        putenv('STATUS_LIGHTS_CACHE_TTL=invalid');
        putenv('STATUS_LIGHTS_STALE_TTL=999999');
        putenv('STATUS_LIGHTS_HTTP_CACHE_TTL=-5');
        putenv('STATUS_LIGHTS_GITHUB_TIMEOUT=99');
        $config = status_lights_config();
        expectSame('fallback-token', $config['github_token']);
        expectSame(60, $config['cache_ttl']);
        expectSame(86400, $config['stale_ttl']);
        expectSame(0, $config['http_cache_ttl']);
        expectSame(30, $config['github_timeout']);

        putenv('STATUS_LIGHTS_GITHUB_TOKEN=primary-token');
        expectSame('primary-token', status_lights_config()['github_token']);
    } finally {
        foreach ($names as $name) {
            putenv($name);
        }
    }

    $invalidRoutes = [
        'http://[',
        '/' . str_repeat('a', 2049),
        '/wrong/owner/repository/workflow.svg',
        '/github/owner/repository/workflow.yml',
        '/github/owner/repository/workflow.yml/size.svg',
        '/github/owner/repository/workflow.yml/size/40/size/40.svg',
        '/github/owner/repository/workflow.yml/font/comic.svg',
        '/github/owner/repository/workflow.yml/success-color/nope.svg',
        '/github/owner/repository/workflow.yml/text/' . rawurlencode(str_repeat('x', 81)) . '.svg',
        '/github/owner/repository/workflow.yml/job/' . rawurlencode(str_repeat('x', 101)) . '.svg',
        '/github/owner/repository/workflow.yml/text/%C3%28.svg',
        '/github/owner/repository/workflow.yml/job/%C3%28.svg',
    ];
    foreach ($invalidRoutes as $route) {
        expectRouteFailure($route);
    }

    $request = status_lights_parse_request(
        '/github/owner/repository/workflow.yml/width/120/font/serif.svg',
    );
    expectSame(120, $request->width);
    expectSame('serif', $request->font);
});

test('covers cache corruption and rendering helpers', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-cache-tests-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $key = 'owner/repository/workflow.yml';
    $path = status_lights_cache_path($directory, $key);

    try {
        expectSame(null, status_lights_read_cache($directory, $key));
        file_put_contents($path, '{');
        expectSame(null, status_lights_read_cache($directory, $key));
        file_put_contents($path, '[]');
        expectSame(null, status_lights_read_cache($directory, $key));
        file_put_contents($path, '{"state":"not-a-state","fetched_at":1}');
        expectSame(null, status_lights_read_cache($directory, $key));

        $config = [
            'cache_directory' => $directory,
            'cache_ttl' => 60,
            'stale_ttl' => 3600,
            'http_cache_ttl' => 60,
            'github_timeout' => 5,
            'github_token' => null,
        ];
        $provider = static fn (): string => 'unsupported';
        $result = status_lights_resolve_state(requestFixture(), $config, $provider, 1000);
        expectSame(StatusLightState::Unknown, $result['state']);

        $notDirectory = $directory . '/file';
        file_put_contents($notDirectory, 'x');
        status_lights_write_cache($notDirectory, $key, StatusLightState::Success, 1);

        expect(str_contains(status_lights_render_error('<unsafe>'), '&lt;unsafe&gt;'));
        expectSame(40, status_lights_automatic_width('', 40, 16));
        expectSame('#000000', status_lights_contrast_color('ffffff'));
        expectSame('#ffffff', status_lights_contrast_color('000000'));
    } finally {
        removeTestDirectory($directory);
    }
});

test('covers app storage, signatures, installations, and ignored webhook variants', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-app-coverage-' . bin2hex(random_bytes(6));
    putenv('STATUS_LIGHTS_APP_STORE_DIR=' . $directory);
    $previousSignature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;
    $previousLength = $_SERVER['CONTENT_LENGTH'] ?? null;

    try {
        expectSame(null, status_lights_app_read(StatusLightsAppStoreKind::Runs, '999'));
        expectSame(0, status_lights_app_prune_runs_older_than(100));
        expectThrows(
            InvalidArgumentException::class,
            static fn (): int => status_lights_app_prune_records_older_than(
                StatusLightsAppStoreKind::Statuses,
                100,
            ),
        );

        status_lights_app_write(StatusLightsAppStoreKind::Runs, '999', ['ok' => true]);
        expectSame(['ok' => true], status_lights_app_read(StatusLightsAppStoreKind::Runs, '999'));
        file_put_contents($directory . '/runs/999.json', '{');
        expectSame(null, status_lights_app_read(StatusLightsAppStoreKind::Runs, '999'));
        file_put_contents($directory . '/runs/999.json', '"scalar"');
        expectSame(null, status_lights_app_read(StatusLightsAppStoreKind::Runs, '999'));
        status_lights_app_delete(StatusLightsAppStoreKind::Runs, '999');

        putenv('STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET');
        unset($_SERVER['HTTP_X_HUB_SIGNATURE_256']);
        expect(!status_lights_app_verify_signature('body'));
        putenv('STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET=secret');
        $_SERVER['HTTP_X_HUB_SIGNATURE_256'] = 'sha256=' . hash_hmac('sha256', 'body', 'secret');
        expect(status_lights_app_verify_signature('body'));
        $_SERVER['HTTP_X_HUB_SIGNATURE_256'] = [];
        expect(!status_lights_app_verify_signature('body'));

        unset($_SERVER['CONTENT_LENGTH']);
        expectSame('', status_lights_app_read_webhook_body());
        expectThrows(
            RuntimeException::class,
            static fn (): string => status_lights_app_read_webhook_body(false),
        );
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, str_repeat('x', 65537));
        rewind($stream);
        putenv('STATUS_LIGHTS_MAX_WEBHOOK_BYTES=65536');
        expectThrows(
            StatusLightsPayloadTooLargeException::class,
            static fn (): string => status_lights_app_read_webhook_body($stream),
        );
        fclose($stream);

        expectSame(null, status_lights_app_normalize_delivery_id(1));
        expectThrows(
            InvalidArgumentException::class,
            static fn (): string => status_lights_app_delivery_key('invalid'),
        );
        expect(
            status_lights_app_status_key('Owner', 'Repo', 'flow.yml')
                !== status_lights_app_status_key('Owner', 'Repo', 'flow.yml', 'Job'),
        );

        status_lights_app_mark_repository([], null, true);
        $repository = [
            'name' => 'repo',
            'default_branch' => 'main',
            'owner' => ['login' => 'owner'],
        ];
        status_lights_app_handle_installation('installation', [
            'action' => 'suspend',
            'installation' => ['id' => 1],
            'repositories' => [$repository, 'invalid'],
        ]);
        status_lights_app_handle_installation('installation_repositories', [
            'installation' => ['id' => 1],
            'repositories_added' => [$repository, 'invalid'],
            'repositories_removed' => [$repository, 'invalid'],
        ]);

        status_lights_app_handle_workflow_run([]);
        status_lights_app_handle_workflow_run(['repository' => [], 'workflow_run' => []]);
        status_lights_app_handle_workflow_run([
            'repository' => $repository,
            'workflow_run' => [
                'id' => 2,
                'path' => '.github/workflows/flow.yml',
                'head_branch' => 'feature',
            ],
        ]);

        status_lights_app_handle_workflow_job([]);
        status_lights_app_handle_workflow_job(['repository' => [], 'workflow_job' => []]);
        status_lights_app_handle_workflow_job([
            'repository' => $repository,
            'workflow_job' => [
                'name' => 'Job',
                'run_id' => 2,
                'head_branch' => 'feature',
            ],
        ]);
        status_lights_app_handle_workflow_job([
            'repository' => $repository,
            'workflow_job' => [
                'name' => 'Job',
                'run_id' => 3,
                'head_branch' => 'main',
            ],
        ]);

        $request = status_lights_parse_request('/github/owner/repo/flow.yml.svg');
        $unknown = status_lights_app_resolve($request);
        expectSame(StatusLightState::Unknown, $unknown['state']);
        expectSame('not-installed', $unknown['cache_status']);
        status_lights_app_mark_repository($repository, 1, true);
        $empty = status_lights_app_resolve($request);
        expectSame('app-empty', $empty['cache_status']);
    } finally {
        putenv('STATUS_LIGHTS_APP_STORE_DIR');
        putenv('STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET');
        putenv('STATUS_LIGHTS_MAX_WEBHOOK_BYTES');
        if ($previousSignature === null) {
            unset($_SERVER['HTTP_X_HUB_SIGNATURE_256']);
        } else {
            $_SERVER['HTTP_X_HUB_SIGNATURE_256'] = $previousSignature;
        }
        if ($previousLength === null) {
            unset($_SERVER['CONTENT_LENGTH']);
        } else {
            $_SERVER['CONTENT_LENGTH'] = $previousLength;
        }
        removeTestDirectory($directory);
    }
});

if (defined('STATUS_LIGHTS_PHPUNIT')) {
    return $GLOBALS['tests'];
}

$failures = 0;

foreach ($tests as [$name, $test]) {
    try {
        $test();
        fwrite(STDOUT, "PASS  {$name}\n");
    } catch (Throwable $exception) {
        $failures++;
        fwrite(STDERR, "FAIL  {$name}\n      {$exception->getMessage()}\n");
    }
}

fwrite(STDOUT, sprintf("\n%d tests, %d failures.\n", count($tests), $failures));

exit($failures === 0 ? 0 : 1);
