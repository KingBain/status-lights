<?php

declare(strict_types=1);

define('STATUS_LIGHTS_TESTING', true);
require dirname(__DIR__) . '/index.php';

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

/** @return array<string, mixed> */
function requestFixture(): array
{
    return status_lights_parse_request('/github/KingBain/status-lights/pages.yml.svg');
}

test('parses the canonical route with defaults', static function (): void {
    $request = requestFixture();

    expectSame('KingBain', $request['owner']);
    expectSame('status-lights', $request['repository']);
    expectSame('pages.yml', $request['workflow']);
    expectSame(40, $request['height']);
    expectSame(null, $request['width']);
    expectSame('', $request['text']);
});

test('parses every URL emitted by the browser builder', static function (): void {
    $request = status_lights_parse_request(
        '/github/KingBain/status-lights/pages.yml/size/48/font/mono/font-size/18/radius/8'
        . '/success-color/00aa00/failure-color/aa0000/running-color/ffaa00/unknown-color/777777'
        . '/text/Build%3A%20%7Bstatus%7D.svg',
    );

    expectSame(48, $request['height']);
    expectSame('mono', $request['font']);
    expectSame(18, $request['font_size']);
    expectSame('Build: {status}', $request['text']);
    expectSame('00aa00', $request['colors'][STATUS_LIGHTS_SUCCESS]);
});

test('decodes a double-encoded slash inside text', static function (): void {
    $request = status_lights_parse_request(
        '/github/KingBain/status-lights/pages.yml/text/Build%252FDeploy.svg',
    );

    expectSame('Build/Deploy', $request['text']);
});

test('rejects unsafe or unsupported route options', static function (): void {
    expectRouteFailure('/github/owner/repository/workflow.yml/size/500.svg');
    expectRouteFailure('/github/owner/repository/workflow.yml/surprise/value.svg');
    expectRouteFailure('/github/owner/repository/workflow.yml/text/%00.svg');
});

test('maps GitHub workflow runs to stable states', static function (): void {
    expectSame(
        STATUS_LIGHTS_RUNNING,
        status_lights_map_run_state(['status' => 'in_progress']),
    );
    expectSame(
        STATUS_LIGHTS_SUCCESS,
        status_lights_map_run_state(['status' => 'completed', 'conclusion' => 'success']),
    );
    expectSame(
        STATUS_LIGHTS_FAILURE,
        status_lights_map_run_state(['status' => 'completed', 'conclusion' => 'timed_out']),
    );
    expectSame(
        STATUS_LIGHTS_UNKNOWN,
        status_lights_map_run_state(['status' => 'completed', 'conclusion' => 'skipped']),
    );
});

test('renders a safe accessible SVG', static function (): void {
    $request = status_lights_parse_request(
        '/github/KingBain/status-lights/pages.yml/text/Build%3A%20%7Bstatus%7D%20%26%20safe.svg',
    );
    $svg = status_lights_render_svg($request, [
        'state' => STATUS_LIGHTS_SUCCESS,
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
        'state' => STATUS_LIGHTS_UNKNOWN,
        'cache_status' => 'miss',
        'fetched_at' => 1,
    ]);

    expect(str_contains($svg, 'width="40" height="40"'));
    expect(!str_contains($svg, '<text'));
});

test('caches GitHub state and falls back to stale data', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-tests-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $calls = 0;
    $fail = false;
    $provider = static function () use (&$calls, &$fail): string {
        $calls++;

        if ($fail) {
            throw new RuntimeException('Upstream unavailable.');
        }

        return STATUS_LIGHTS_SUCCESS;
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
    expectSame(STATUS_LIGHTS_SUCCESS, $stale['state']);

    foreach (glob($directory . '/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory);
});

test('preserves workflow filename case in cache keys', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-tests-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $calls = 0;
    $provider = static function (string $owner, string $repository, string $workflow) use (&$calls): string {
        $calls++;

        return $workflow === 'build.yml' ? STATUS_LIGHTS_SUCCESS : STATUS_LIGHTS_FAILURE;
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

    expectSame(STATUS_LIGHTS_SUCCESS, $lowercaseResult['state']);
    expectSame(STATUS_LIGHTS_FAILURE, $uppercaseResult['state']);
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
    $provider = static function (): string {
        throw new RuntimeException('Upstream unavailable.');
    };

    $result = status_lights_resolve_state(requestFixture(), $config, $provider, 1000);

    expectSame(STATUS_LIGHTS_UNKNOWN, $result['state']);
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
