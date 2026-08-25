<?php

declare(strict_types=1);

use StatusLights\FileCache;
use StatusLights\GeneratorRequest;
use StatusLights\GitHubClient;
use StatusLights\InvalidRoute;
use StatusLights\RouteParser;
use StatusLights\StatusResolver;
use StatusLights\StatusResult;
use StatusLights\SvgRenderer;
use StatusLights\WorkflowState;
use StatusLights\WorkflowStatusProvider;

require dirname(__DIR__) . '/src/bootstrap.php';

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
        (new RouteParser())->parse($uri);
    } catch (InvalidRoute) {
        return;
    }

    throw new RuntimeException('Expected route parsing to fail.');
}

function requestFixture(): GeneratorRequest
{
    return (new RouteParser())->parse('/github/KingBain/status-lights/pages.yml.svg');
}

test('parses the canonical route with defaults', static function (): void {
    $request = requestFixture();

    expectSame('KingBain', $request->owner);
    expectSame('status-lights', $request->repository);
    expectSame('pages.yml', $request->workflow);
    expectSame(40, $request->height);
    expectSame(null, $request->width);
    expectSame('', $request->text);
});

test('parses every URL emitted by the browser builder', static function (): void {
    $request = (new RouteParser())->parse(
        '/github/KingBain/status-lights/pages.yml/size/48/font/mono/font-size/18/radius/8'
        . '/success-color/00aa00/failure-color/aa0000/running-color/ffaa00/unknown-color/777777'
        . '/text/Build%3A%20%7Bstatus%7D.svg',
    );

    expectSame(48, $request->height);
    expectSame('mono', $request->font);
    expectSame(18, $request->fontSize);
    expectSame('Build: {status}', $request->text);
    expectSame('00aa00', $request->colors[WorkflowState::SUCCESS]);
});

test('decodes a double-encoded slash inside text', static function (): void {
    $request = (new RouteParser())->parse(
        '/github/KingBain/status-lights/pages.yml/text/Build%252FDeploy.svg',
    );

    expectSame('Build/Deploy', $request->text);
});

test('rejects unsafe or unsupported route options', static function (): void {
    expectRouteFailure('/github/owner/repository/workflow.yml/size/500.svg');
    expectRouteFailure('/github/owner/repository/workflow.yml/surprise/value.svg');
    expectRouteFailure('/github/owner/repository/workflow.yml/text/%00.svg');
});

test('maps GitHub workflow runs to stable states', static function (): void {
    $client = new GitHubClient(1);

    expectSame(WorkflowState::RUNNING, $client->mapRunToState(['status' => 'in_progress']));
    expectSame(
        WorkflowState::SUCCESS,
        $client->mapRunToState(['status' => 'completed', 'conclusion' => 'success']),
    );
    expectSame(
        WorkflowState::FAILURE,
        $client->mapRunToState(['status' => 'completed', 'conclusion' => 'timed_out']),
    );
    expectSame(
        WorkflowState::UNKNOWN,
        $client->mapRunToState(['status' => 'completed', 'conclusion' => 'skipped']),
    );
});

test('renders a safe, accessible SVG', static function (): void {
    $request = (new RouteParser())->parse(
        '/github/KingBain/status-lights/pages.yml/text/Build%3A%20%7Bstatus%7D%20%26%20safe.svg',
    );
    $svg = (new SvgRenderer())->render(
        $request,
        new StatusResult(WorkflowState::SUCCESS, 'miss', 1),
    );

    expect(str_contains($svg, 'data-state="success"'));
    expect(str_contains($svg, 'Build: Success &amp; safe'));
    expect(!str_contains($svg, 'Build: Success & safe'));
    expect(str_contains($svg, '<title id="title">'));
});

test('uses a square SVG when no text is requested', static function (): void {
    $svg = (new SvgRenderer())->render(
        requestFixture(),
        new StatusResult(WorkflowState::UNKNOWN, 'miss', 1),
    );

    expect(str_contains($svg, 'width="40" height="40"'));
    expect(!str_contains($svg, '<text'));
});

test('caches GitHub state and falls back to stale data', static function (): void {
    $directory = sys_get_temp_dir() . '/status-lights-tests-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700, true);
    $provider = new class implements WorkflowStatusProvider {
        public int $calls = 0;
        public bool $fail = false;

        public function fetchState(string $owner, string $repository, string $workflow): string
        {
            $this->calls++;

            if ($this->fail) {
                throw new RuntimeException('Upstream unavailable.');
            }

            return WorkflowState::SUCCESS;
        }
    };
    $resolver = new StatusResolver($provider, new FileCache($directory), 60, 3600);

    $first = $resolver->resolve(requestFixture(), 1000);
    $second = $resolver->resolve(requestFixture(), 1030);
    $provider->fail = true;
    $stale = $resolver->resolve(requestFixture(), 1100);

    expectSame('miss', $first->cacheStatus);
    expectSame('hit', $second->cacheStatus);
    expectSame('stale', $stale->cacheStatus);
    expectSame(2, $provider->calls);
    expectSame(WorkflowState::SUCCESS, $stale->state);

    foreach (glob($directory . '/*') ?: [] as $path) {
        unlink($path);
    }
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

