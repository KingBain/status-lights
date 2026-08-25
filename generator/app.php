<?php

declare(strict_types=1);

define('STATUS_LIGHTS_TESTING', true);
require __DIR__ . '/index.php';

function status_lights_app_store_dir(): string
{
    $configured = status_lights_environment('STATUS_LIGHTS_APP_STORE_DIR');
    return $configured !== '' ? $configured : __DIR__ . '/app-data';
}

function status_lights_app_webhook_secret(): string
{
    return status_lights_environment('STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET');
}

function status_lights_app_key(string ...$parts): string
{
    return hash('sha256', implode('/', $parts));
}

function status_lights_app_write(string $kind, string $key, array $value): void
{
    $directory = status_lights_app_store_dir() . '/' . $kind;
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create app data directory.');
    }

    $path = $directory . '/' . $key . '.json';
    $temporary = tempnam($directory, 'status-lights-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Unable to create temporary app data file.');
    }

    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to write app data.');
    }

    @chmod($path, 0644);
}

function status_lights_app_read(string $kind, string $key): ?array
{
    $path = status_lights_app_store_dir() . '/' . $kind . '/' . $key . '.json';
    if (!is_file($path)) {
        return null;
    }

    $contents = @file_get_contents($path);
    if (!is_string($contents)) {
        return null;
    }

    try {
        $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }

    return is_array($value) ? $value : null;
}

function status_lights_app_repo_key(string $owner, string $repository): string
{
    return status_lights_app_key(strtolower($owner), strtolower($repository));
}

function status_lights_app_status_key(string $owner, string $repository, string $workflow, ?string $job = null): string
{
    $parts = [strtolower($owner), strtolower($repository), $workflow];
    if ($job !== null) {
        $parts[] = 'job';
        $parts[] = $job;
    }
    return status_lights_app_key(...$parts);
}

function status_lights_app_verify_signature(string $body): bool
{
    $secret = status_lights_app_webhook_secret();
    $signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if ($secret === '' || !is_string($signature) || !str_starts_with($signature, 'sha256=')) {
        return false;
    }

    $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
    return hash_equals($expected, $signature);
}

function status_lights_app_mark_repository(array $repository, ?int $installationId, bool $installed): void
{
    $owner = $repository['owner']['login'] ?? null;
    $name = $repository['name'] ?? null;
    if (!is_string($owner) || !is_string($name)) {
        return;
    }

    status_lights_app_write('repositories', status_lights_app_repo_key($owner, $name), [
        'owner' => $owner,
        'repository' => $name,
        'default_branch' => is_string($repository['default_branch'] ?? null) ? $repository['default_branch'] : null,
        'installation_id' => $installationId,
        'installed' => $installed,
        'updated_at' => time(),
    ]);
}

function status_lights_app_handle_installation(string $event, array $payload): void
{
    $action = (string) ($payload['action'] ?? '');
    $installationId = is_int($payload['installation']['id'] ?? null) ? $payload['installation']['id'] : null;

    if ($event === 'installation') {
        $repositories = $payload['repositories'] ?? [];
        $installed = $action !== 'deleted' && $action !== 'suspend';
        if (is_array($repositories)) {
            foreach ($repositories as $repository) {
                if (is_array($repository)) {
                    status_lights_app_mark_repository($repository, $installationId, $installed);
                }
            }
        }
        return;
    }

    if ($event === 'installation_repositories') {
        foreach (($payload['repositories_added'] ?? []) as $repository) {
            if (is_array($repository)) {
                status_lights_app_mark_repository($repository, $installationId, true);
            }
        }
        foreach (($payload['repositories_removed'] ?? []) as $repository) {
            if (is_array($repository)) {
                status_lights_app_mark_repository($repository, $installationId, false);
            }
        }
    }
}

function status_lights_app_handle_workflow_run(array $payload): void
{
    $repository = $payload['repository'] ?? null;
    $run = $payload['workflow_run'] ?? null;
    if (!is_array($repository) || !is_array($run)) {
        return;
    }

    $owner = $repository['owner']['login'] ?? null;
    $name = $repository['name'] ?? null;
    $defaultBranch = $repository['default_branch'] ?? null;
    $headBranch = $run['head_branch'] ?? null;
    $path = $run['path'] ?? null;
    $runId = $run['id'] ?? null;

    if (!is_string($owner) || !is_string($name) || !is_string($path) || !is_int($runId)) {
        return;
    }

    if (is_string($defaultBranch) && is_string($headBranch) && $headBranch !== $defaultBranch) {
        return;
    }

    $workflow = basename($path);
    $state = status_lights_map_run_state($run);
    $now = time();

    status_lights_app_write('runs', (string) $runId, [
        'owner' => $owner,
        'repository' => $name,
        'workflow' => $workflow,
        'updated_at' => $now,
    ]);
    status_lights_app_write('statuses', status_lights_app_status_key($owner, $name, $workflow), [
        'state' => $state,
        'updated_at' => $now,
        'run_id' => $runId,
    ]);
    status_lights_app_mark_repository($repository, is_int($payload['installation']['id'] ?? null) ? $payload['installation']['id'] : null, true);
}

function status_lights_app_handle_workflow_job(array $payload): void
{
    $repository = $payload['repository'] ?? null;
    $job = $payload['workflow_job'] ?? null;
    if (!is_array($repository) || !is_array($job)) {
        return;
    }

    $owner = $repository['owner']['login'] ?? null;
    $name = $repository['name'] ?? null;
    $jobName = $job['name'] ?? null;
    $runId = $job['run_id'] ?? null;
    $headBranch = $job['head_branch'] ?? null;
    $defaultBranch = $repository['default_branch'] ?? null;

    if (!is_string($owner) || !is_string($name) || !is_string($jobName) || !is_int($runId)) {
        return;
    }

    if (is_string($defaultBranch) && is_string($headBranch) && $headBranch !== $defaultBranch) {
        return;
    }

    $run = status_lights_app_read('runs', (string) $runId);
    $workflow = $run['workflow'] ?? null;
    if (!is_string($workflow)) {
        return;
    }

    status_lights_app_write('statuses', status_lights_app_status_key($owner, $name, $workflow, $jobName), [
        'state' => status_lights_map_run_state($job),
        'updated_at' => time(),
        'run_id' => $runId,
    ]);
}

function status_lights_app_handle_webhook(): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        status_lights_send_json(['error' => 'Method not allowed'], 405);
    }

    $body = file_get_contents('php://input');
    if (!is_string($body) || !status_lights_app_verify_signature($body)) {
        status_lights_send_json(['error' => 'Invalid webhook signature'], 401);
    }

    try {
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        status_lights_send_json(['error' => 'Invalid JSON'], 400);
    }

    if (!is_array($payload)) {
        status_lights_send_json(['error' => 'Invalid payload'], 400);
    }

    $event = (string) ($_SERVER['HTTP_X_GITHUB_EVENT'] ?? '');
    if ($event === 'ping') {
        status_lights_send_json(['status' => 'ok', 'event' => 'ping']);
    }

    if ($event === 'installation' || $event === 'installation_repositories') {
        status_lights_app_handle_installation($event, $payload);
    } elseif ($event === 'workflow_run') {
        status_lights_app_handle_workflow_run($payload);
    } elseif ($event === 'workflow_job') {
        status_lights_app_handle_workflow_job($payload);
    }

    status_lights_send_json(['status' => 'accepted', 'event' => $event], 202);
}

function status_lights_app_resolve(array $request): array
{
    $owner = (string) $request['owner'];
    $repository = (string) $request['repository'];
    $workflow = (string) $request['workflow'];
    $job = is_string($request['job']) ? $request['job'] : null;
    $repo = status_lights_app_read('repositories', status_lights_app_repo_key($owner, $repository));
    $installed = ($repo['installed'] ?? false) === true;
    $status = status_lights_app_read('statuses', status_lights_app_status_key($owner, $repository, $workflow, $job));

    if (!$installed || !is_array($status) || !in_array($status['state'] ?? null, STATUS_LIGHTS_STATES, true)) {
        return ['state' => STATUS_LIGHTS_UNKNOWN, 'cache_status' => $installed ? 'app-empty' : 'not-installed', 'fetched_at' => time()];
    }

    return [
        'state' => $status['state'],
        'cache_status' => 'webhook',
        'fetched_at' => is_int($status['updated_at'] ?? null) ? $status['updated_at'] : time(),
    ];
}

function status_lights_app_main(): never
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if ($path === '/webhooks/github') {
        status_lights_app_handle_webhook();
    }

    if ($path === '/') {
        header('Location: https://statuslights.dev/', true, 302);
        exit;
    }

    if ($path === '/health') {
        $directory = status_lights_app_store_dir();
        $parent = dirname($directory);
        $writable = is_dir($directory) ? is_writable($directory) : (is_dir($parent) && is_writable($parent));
        status_lights_send_json([
            'status' => $writable && status_lights_app_webhook_secret() !== '' ? 'ok' : 'degraded',
            'service' => 'status-lights-github-app',
            'checks' => [
                'app_store_writable' => $writable,
                'webhook_secret_configured' => status_lights_app_webhook_secret() !== '',
            ],
        ], $writable && status_lights_app_webhook_secret() !== '' ? 200 : 503);
    }

    try {
        $request = status_lights_parse_request($_SERVER['REQUEST_URI'] ?? '/');
        $result = status_lights_app_resolve($request);
        $config = status_lights_config();
        status_lights_send_svg(status_lights_render_svg($request, $result), 200, (int) $config['http_cache_ttl'], $result);
    } catch (StatusLightsRouteException $exception) {
        status_lights_send_svg(status_lights_render_error($exception->getMessage()), $exception->statusCode, 0);
    } catch (Throwable) {
        status_lights_send_svg(status_lights_render_error('Status temporarily unavailable'), 500, 0);
    }
}

status_lights_app_main();
