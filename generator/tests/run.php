<?php

declare(strict_types=1);

define('STATUS_LIGHTS_TESTING', true);
define('STATUS_LIGHTS_APP_TESTING', true);
require dirname(__DIR__) . '/app.php';

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

test('prunes only expired workflow run records', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-app-tests-' . bin2hex(random_bytes(6));
    putenv('STATUS_LIGHTS_APP_STORE_DIR=' . $directory);

    try {
        status_lights_app_write('runs', 'expired', ['updated_at' => 100]);
        status_lights_app_write('runs', 'current', ['updated_at' => 200]);
        status_lights_app_write('statuses', 'expired', ['updated_at' => 100]);
        touch($directory . '/runs/expired.json', 100);
        touch($directory . '/runs/current.json', 200);
        file_put_contents($directory . '/runs/keep.txt', 'not a run record');
        touch($directory . '/runs/keep.txt', 100);

        expectSame(1, status_lights_app_prune_runs_older_than(150));
        expect(!is_file($directory . '/runs/expired.json'));
        expect(is_file($directory . '/runs/current.json'));
        expect(is_file($directory . '/runs/keep.txt'));
        expect(is_file($directory . '/statuses/expired.json'));
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
