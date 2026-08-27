<?php

declare(strict_types=1);

require_once __DIR__ . '/core.php';

final class StatusLightsPayloadTooLargeException extends RuntimeException
{
}

enum StatusLightsAppStoreKind: string
{
    case Deliveries = 'deliveries';
    case Repositories = 'repositories';
    case Runs = 'runs';
    case Statuses = 'statuses';

    public function keyPattern(): string
    {
        return match ($this) {
            self::Runs => '/\A[1-9][0-9]*\z/D',
            default => '/\A[a-f0-9]{64}\z/D',
        };
    }
}

function status_lights_app_store_dir(?StatusLightsSystem $system = null): string
{
    $system ??= new StatusLightsRealSystem();
    $configured = status_lights_environment('STATUS_LIGHTS_APP_STORE_DIR', $system);
    return $configured !== '' ? $configured : __DIR__ . '/app-data';
}

function status_lights_app_webhook_secret(StatusLightsSystem $system): string
{
    return status_lights_environment('STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET', $system);
}

function status_lights_app_max_webhook_bytes(StatusLightsSystem $system): int
{
    return status_lights_environment_integer('STATUS_LIGHTS_MAX_WEBHOOK_BYTES', 1048576, 65536, 26214400, $system);
}

function status_lights_app_key(string ...$parts): string
{
    return hash('sha256', implode('/', $parts));
}

function status_lights_app_store_kind_directory(StatusLightsSystem $system, StatusLightsAppStoreKind $kind): string
{
    return rtrim(status_lights_app_store_dir($system), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $kind->value;
}

function status_lights_app_record_path(StatusLightsSystem $system, StatusLightsAppStoreKind $kind, string $key): string
{
    if (preg_match($kind->keyPattern(), $key) !== 1) {
        throw new InvalidArgumentException('Invalid app data record key.');
    }
    return status_lights_app_store_kind_directory($system, $kind) . DIRECTORY_SEPARATOR . $key . '.json';
}

function status_lights_app_ensure_store_directory(StatusLightsSystem $system, StatusLightsAppStoreKind $kind): string
{
    $directory = status_lights_app_store_kind_directory($system, $kind);
    if (!$system->isDir($directory) && !$system->mkdir($directory, 0755, true) && !$system->isDir($directory)) {
        throw new RuntimeException('Unable to create app data directory.');
    }
    return $directory;
}

/** @param array<string, mixed> $value */
function status_lights_app_write(StatusLightsSystem $system, StatusLightsAppStoreKind $kind, string $key, array $value): void
{
    $path = status_lights_app_record_path($system, $kind, $key);
    $directory = status_lights_app_ensure_store_directory($system, $kind);
    $temporary = $system->tempnam($directory, 'status-lights-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Unable to create temporary app data file.');
    }

    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if ($system->filePutContents($temporary, $json, LOCK_EX) === false || !$system->rename($temporary, $path)) {
        $system->unlink($temporary);
        throw new RuntimeException('Unable to write app data.');
    }
    $system->chmod($path, 0644);
}

/** @param array<string, mixed> $value */
function status_lights_app_create(StatusLightsSystem $system, StatusLightsAppStoreKind $kind, string $key, array $value): bool
{
    $path = status_lights_app_record_path($system, $kind, $key);
    status_lights_app_ensure_store_directory($system, $kind);
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

    $result = $system->createAtomicFile($path, $json);
    if ($result === false) {
        return false;
    }
    if ($result === null) {
        throw new RuntimeException('Unable to create app data record.');
    }

    $system->chmod($path, 0644);
    return true;
}

function status_lights_app_delete(StatusLightsSystem $system, StatusLightsAppStoreKind $kind, string $key): void
{
    $path = status_lights_app_record_path($system, $kind, $key);
    if ($system->isFile($path) && !$system->unlink($path)) {
        throw new RuntimeException('Unable to delete app data record.');
    }
}

/** @return array<string, mixed>|null */
function status_lights_app_read(StatusLightsSystem $system, StatusLightsAppStoreKind $kind, string $key): ?array
{
    $path = status_lights_app_record_path($system, $kind, $key);
    if (!$system->isFile($path)) {
        return null;
    }
    $contents = $system->fileGetContents($path);
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

function status_lights_app_prune_records_older_than(StatusLightsAppStoreKind $kind, int $cutoff, ?StatusLightsSystem $system = null): int
{
    $system ??= new StatusLightsRealSystem();
    if (!in_array($kind, [StatusLightsAppStoreKind::Deliveries, StatusLightsAppStoreKind::Runs], true)) {
        throw new InvalidArgumentException('This app data record kind may not be pruned.');
    }
    $directory = status_lights_app_store_kind_directory($system, $kind);
    if (!$system->isDir($directory)) {
        return 0;
    }

    $deleted = 0;
    foreach ($system->getJsonFilesInDirectory($directory) as $pathname => $basename) {
        if (preg_match($kind->keyPattern(), $basename) !== 1) {
            continue;
        }
        $modifiedAt = $system->filemtime($pathname);
        if (is_int($modifiedAt) && $modifiedAt < $cutoff && $system->unlink($pathname)) {
            $deleted++;
        }
    }
    return $deleted;
}

function status_lights_app_prune_runs_older_than(int $cutoff, ?StatusLightsSystem $system = null): int
{
    return status_lights_app_prune_records_older_than(StatusLightsAppStoreKind::Runs, $cutoff, $system);
}
function status_lights_app_prune_deliveries_older_than(int $cutoff, ?StatusLightsSystem $system = null): int
{
    return status_lights_app_prune_records_older_than(StatusLightsAppStoreKind::Deliveries, $cutoff, $system);
}

/** @param array<string, mixed> $server */
function status_lights_app_read_webhook_body(StatusLightsSystem $system, array $server): string
{
    $maximumBytes = status_lights_app_max_webhook_bytes($system);
    $contentLength = $server['CONTENT_LENGTH'] ?? null;

    if (is_string($contentLength) && filter_var($contentLength, FILTER_VALIDATE_INT) !== false && (int) $contentLength > $maximumBytes) {
        throw new StatusLightsPayloadTooLargeException('Webhook payload is too large.');
    }

    $body = $system->readInput($maximumBytes);
    if (strlen($body) > $maximumBytes) {
        throw new StatusLightsPayloadTooLargeException('Webhook payload is too large.');
    }

    // We expect valid JSON, so an empty body on a POST is fundamentally unreadable/invalid
    if ($body === '' && ($server['REQUEST_METHOD'] ?? '') === 'POST') {
        throw new RuntimeException('Unable to read webhook payload.');
    }
    return $body;
}

function status_lights_app_repo_key(string $owner, string $repository): string
{
    return status_lights_app_key(strtolower($owner), strtolower($repository));
}
function status_lights_app_status_key(string $owner, string $repository, string $workflow, ?string $job = null): string
{
    $parts = [strtolower($owner), strtolower($repository), $workflow];
    if ($job !== null) {
        array_push($parts, 'job', $job);
    }
    return status_lights_app_key(...$parts);
}

function status_lights_app_normalize_delivery_id(mixed $value): ?string
{
    if (!is_string($value) || preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD', $value) !== 1) {
        return null;
    }
    return strtolower($value);
}

function status_lights_app_delivery_key(string $deliveryId): string
{
    $normalized = status_lights_app_normalize_delivery_id($deliveryId);
    if ($normalized === null) {
        throw new InvalidArgumentException('Invalid GitHub webhook delivery ID.');
    }
    return status_lights_app_key('delivery', $normalized);
}

function status_lights_app_claim_delivery(StatusLightsSystem $system, string $deliveryId): bool
{
    return status_lights_app_create($system, StatusLightsAppStoreKind::Deliveries, status_lights_app_delivery_key($deliveryId), ['received_at' => $system->time()]);
}

function status_lights_app_release_delivery(StatusLightsSystem $system, string $deliveryId): void
{
    status_lights_app_delete($system, StatusLightsAppStoreKind::Deliveries, status_lights_app_delivery_key($deliveryId));
}

/** @param array<string, mixed> $server */
function status_lights_app_verify_signature(StatusLightsSystem $system, string $body, array $server): bool
{
    $secret = status_lights_app_webhook_secret($system);
    $signature = $server['HTTP_X_HUB_SIGNATURE_256'] ?? '';
    if ($secret === '' || !is_string($signature) || !str_starts_with($signature, 'sha256=')) {
        return false;
    }
    return hash_equals('sha256=' . hash_hmac('sha256', $body, $secret), $signature);
}

/** @param array<string, mixed> $repository */
function status_lights_app_mark_repository(StatusLightsSystem $system, array $repository, ?int $installationId, bool $installed): void
{
    $owner = $repository['owner']['login'] ?? null;
    $name = $repository['name'] ?? null;
    if (!is_string($owner) || !is_string($name)) {
        return;
    }
    status_lights_app_write($system, StatusLightsAppStoreKind::Repositories, status_lights_app_repo_key($owner, $name), [
        'owner' => $owner, 'repository' => $name,
        'default_branch' => is_string($repository['default_branch'] ?? null) ? $repository['default_branch'] : null,
        'installation_id' => $installationId, 'installed' => $installed, 'updated_at' => $system->time(),
    ]);
}

/** @param array<string, mixed> $payload */
function status_lights_app_handle_installation(StatusLightsSystem $system, string $event, array $payload): void
{
    $action = (string) ($payload['action'] ?? '');
    $installationId = is_int($payload['installation']['id'] ?? null) ? $payload['installation']['id'] : null;

    if ($event === 'installation') {
        $repositories = $payload['repositories'] ?? [];
        $installed = $action !== 'deleted' && $action !== 'suspend';
        if (is_array($repositories)) {
            foreach ($repositories as $repository) {
                if (is_array($repository)) {
                    status_lights_app_mark_repository($system, $repository, $installationId, $installed);
                }
            }
        }
        return;
    }

    if ($event === 'installation_repositories') {
        foreach (($payload['repositories_added'] ?? []) as $repository) {
            if (is_array($repository)) {
                status_lights_app_mark_repository($system, $repository, $installationId, true);
            }
        }
        foreach (($payload['repositories_removed'] ?? []) as $repository) {
            if (is_array($repository)) {
                status_lights_app_mark_repository($system, $repository, $installationId, false);
            }
        }
    }
}

/** @param array<string, mixed> $payload */
function status_lights_app_handle_workflow_run(StatusLightsSystem $system, array $payload): void
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

    if (!is_string($owner) || !is_string($name) || !is_string($path) || !is_int($runId) || $runId < 1) {
        return;
    }
    if (is_string($defaultBranch) && is_string($headBranch) && $headBranch !== $defaultBranch) {
        return;
    }

    $workflow = basename($path);
    $state = status_lights_map_run_state($run);
    $now = $system->time();

    status_lights_app_write($system, StatusLightsAppStoreKind::Runs, (string) $runId, ['owner' => $owner, 'repository' => $name, 'workflow' => $workflow, 'updated_at' => $now]);
    status_lights_app_write($system, StatusLightsAppStoreKind::Statuses, status_lights_app_status_key($owner, $name, $workflow), ['state' => $state->value, 'updated_at' => $now, 'run_id' => $runId]);
    status_lights_app_mark_repository($system, $repository, is_int($payload['installation']['id'] ?? null) ? $payload['installation']['id'] : null, true);
}

/** @param array<string, mixed> $payload */
function status_lights_app_handle_workflow_job(StatusLightsSystem $system, array $payload): void
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

    if (!is_string($owner) || !is_string($name) || !is_string($jobName) || !is_int($runId) || $runId < 1) {
        return;
    }
    if (is_string($defaultBranch) && is_string($headBranch) && $headBranch !== $defaultBranch) {
        return;
    }

    $run = status_lights_app_read($system, StatusLightsAppStoreKind::Runs, (string) $runId);
    $workflow = $run['workflow'] ?? null;
    if (!is_string($workflow)) {
        return;
    }

    status_lights_app_write($system, StatusLightsAppStoreKind::Statuses, status_lights_app_status_key($owner, $name, $workflow, $jobName), ['state' => status_lights_map_run_state($job)->value, 'updated_at' => $system->time(), 'run_id' => $runId]);
}

/** @param array<string, mixed> $server */
function status_lights_app_handle_webhook(StatusLightsSystem $system, array $server): StatusLightsResponse
{
    if (($server['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return status_lights_create_json_response(['error' => 'Method not allowed'], 405);
    }

    try {
        $body = status_lights_app_read_webhook_body($system, $server);
    } catch (StatusLightsPayloadTooLargeException) {
        return status_lights_create_json_response(['error' => 'Webhook payload is too large'], 413);
    } catch (RuntimeException) {
        return status_lights_create_json_response(['error' => 'Unable to read webhook payload'], 400);
    }

    if (!status_lights_app_verify_signature($system, $body, $server)) {
        return status_lights_create_json_response(['error' => 'Invalid webhook signature'], 401);
    }

    try {
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return status_lights_create_json_response(['error' => 'Invalid JSON'], 400);
    }

    if (!is_array($payload)) {
        return status_lights_create_json_response(['error' => 'Invalid payload'], 400);
    }

    $eventHeader = $server['HTTP_X_GITHUB_EVENT'] ?? null;
    $event = is_string($eventHeader) && preg_match('/\A[a-z0-9_]{1,64}\z/D', $eventHeader) === 1 ? $eventHeader : null;
    if ($event === null) {
        return status_lights_create_json_response(['error' => 'Invalid webhook event'], 400);
    }

    $deliveryId = status_lights_app_normalize_delivery_id($server['HTTP_X_GITHUB_DELIVERY'] ?? null);
    if ($deliveryId === null) {
        return status_lights_create_json_response(['error' => 'Invalid webhook delivery ID'], 400);
    }

    try {
        $claimed = status_lights_app_claim_delivery($system, $deliveryId);
    } catch (Throwable) {
        return status_lights_create_json_response(['error' => 'Unable to record webhook delivery'], 500);
    }

    if (!$claimed) {
        return status_lights_create_json_response(['status' => 'accepted', 'event' => $event, 'duplicate' => true], 202);
    }
    if ($event === 'ping') {
        return status_lights_create_json_response(['status' => 'ok', 'event' => 'ping']);
    }

    try {
        if ($event === 'installation' || $event === 'installation_repositories') {
            status_lights_app_handle_installation($system, $event, $payload);
        } elseif ($event === 'workflow_run') {
            status_lights_app_handle_workflow_run($system, $payload);
        } elseif ($event === 'workflow_job') {
            status_lights_app_handle_workflow_job($system, $payload);
        }
    } catch (Throwable) {
        try {
            status_lights_app_release_delivery($system, $deliveryId);
        } catch (Throwable) {
        }
        return status_lights_create_json_response(['error' => 'Unable to process webhook delivery'], 500);
    }

    return status_lights_create_json_response(['status' => 'accepted', 'event' => $event], 202);
}

/** @return array{state: StatusLightState, cache_status: string, fetched_at: int} */
function status_lights_app_resolve(StatusLightsSystem $system, LightRequest $request): array
{
    $repo = status_lights_app_read($system, StatusLightsAppStoreKind::Repositories, status_lights_app_repo_key($request->owner, $request->repository));
    $installed = ($repo['installed'] ?? false) === true;
    $status = status_lights_app_read($system, StatusLightsAppStoreKind::Statuses, status_lights_app_status_key($request->owner, $request->repository, $request->workflow, $request->job));
    $state = is_string($status['state'] ?? null) ? StatusLightState::tryFrom($status['state']) : null;

    if (!$installed || $state === null) {
        return ['state' => StatusLightState::Unknown, 'cache_status' => $installed ? 'app-empty' : 'not-installed', 'fetched_at' => $system->time()];
    }
    return ['state' => $state, 'cache_status' => 'webhook', 'fetched_at' => is_int($status['updated_at'] ?? null) ? $status['updated_at'] : $system->time()];
}

/** @param array<string, mixed> $server */
function status_lights_app_handle_request(StatusLightsSystem $system, array $server): StatusLightsResponse
{
    $path = parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if ($path === '/webhooks/github') {
        return status_lights_app_handle_webhook($system, $server);
    }
    if ($path === '/') {
        return new StatusLightsResponse(302, ['Location' => 'https://statuslights.dev/'], '');
    }

    if ($path === '/health') {
        $directory = status_lights_app_store_dir($system);
        $parent = dirname($directory);
        $writable = $system->isDir($directory) ? $system->isWritable($directory) : ($system->isDir($parent) && $system->isWritable($parent));
        $hasSecret = status_lights_app_webhook_secret($system) !== '';
        $healthy = $writable && $hasSecret;
        return status_lights_create_json_response([
            'status' => $healthy ? 'ok' : 'degraded', 'service' => 'status-lights-github-app',
            'checks' => ['app_store_writable' => $writable, 'webhook_secret_configured' => $hasSecret],
        ], $healthy ? 200 : 503);
    }

    try {
        $request = status_lights_parse_request($server['REQUEST_URI'] ?? '/');
        $result = status_lights_app_resolve($system, $request);
        $config = status_lights_config($system);
        return status_lights_create_svg_response(status_lights_render_svg($request, $result), 200, (int) $config['http_cache_ttl'], $result, $server);
    } catch (StatusLightsRouteException $exception) {
        return status_lights_create_svg_response(status_lights_render_error($exception->getMessage()), $exception->statusCode, 0, null, $server);
    } catch (Throwable) {
        return status_lights_create_svg_response(status_lights_render_error('Status temporarily unavailable'), 500, 0, null, $server);
    }
}

function status_lights_app_main(): void
{
    $system = new StatusLightsRealSystem();
    $response = status_lights_app_handle_request($system, $_SERVER);

    // @codeCoverageIgnoreStart
    status_lights_emit_response($response);
    exit;
    // @codeCoverageIgnoreEnd
}

if (!defined('STATUS_LIGHTS_APP_TESTING')) {
    // @codeCoverageIgnoreStart
    status_lights_app_main();
    // @codeCoverageIgnoreEnd
}
