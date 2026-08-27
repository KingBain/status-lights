<?php

declare(strict_types=1);

if (!defined('STATUS_LIGHTS_TESTING')) {
    define('STATUS_LIGHTS_TESTING', true);
}

require_once __DIR__ . '/index.php';

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

function status_lights_app_store_dir(): string
{
    $configured = status_lights_environment('STATUS_LIGHTS_APP_STORE_DIR');
    return $configured !== '' ? $configured : __DIR__ . '/app-data';
}

function status_lights_app_webhook_secret(): string
{
    return status_lights_environment('STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET');
}

function status_lights_app_max_webhook_bytes(): int
{
    return status_lights_environment_integer(
        'STATUS_LIGHTS_MAX_WEBHOOK_BYTES',
        1048576,
        65536,
        26214400,
    );
}

function status_lights_app_key(string ...$parts): string
{
    return hash('sha256', implode('/', $parts));
}

function status_lights_app_store_kind_directory(StatusLightsAppStoreKind $kind): string
{
    return rtrim(status_lights_app_store_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . $kind->value;
}

function status_lights_app_record_path(StatusLightsAppStoreKind $kind, string $key): string
{
    if (preg_match($kind->keyPattern(), $key) !== 1) {
        throw new InvalidArgumentException('Invalid app data record key.');
    }

    return status_lights_app_store_kind_directory($kind)
        . DIRECTORY_SEPARATOR
        . $key
        . '.json';
}

function status_lights_app_ensure_store_directory(StatusLightsAppStoreKind $kind): string
{
    $directory = status_lights_app_store_kind_directory($kind);
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create app data directory.');
    }

    return $directory;
}

function status_lights_app_write(StatusLightsAppStoreKind $kind, string $key, array $value): void
{
    $path = status_lights_app_record_path($kind, $key);
    $directory = status_lights_app_ensure_store_directory($kind);
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

function status_lights_app_create(StatusLightsAppStoreKind $kind, string $key, array $value): bool
{
    $path = status_lights_app_record_path($kind, $key);
    status_lights_app_ensure_store_directory($kind);
    $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $handle = @fopen($path, 'x+b');

    if (!is_resource($handle)) {
        if (is_file($path)) {
            return false;
        }

        throw new RuntimeException('Unable to create app data record.');
    }

    try {
        if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
            throw new RuntimeException('Unable to create app data record.');
        }
    } catch (Throwable $exception) {
        fclose($handle);
        @unlink($path);
        throw $exception;
    }

    fclose($handle);
    @chmod($path, 0644);
    return true;
}

function status_lights_app_delete(StatusLightsAppStoreKind $kind, string $key): void
{
    $path = status_lights_app_record_path($kind, $key);
    if (is_file($path) && !@unlink($path)) {
        throw new RuntimeException('Unable to delete app data record.');
    }
}

function status_lights_app_read(StatusLightsAppStoreKind $kind, string $key): ?array
{
    $path = status_lights_app_record_path($kind, $key);
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

function status_lights_app_prune_records_older_than(
    StatusLightsAppStoreKind $kind,
    int $cutoff,
): int
{
    if (!in_array($kind, [StatusLightsAppStoreKind::Deliveries, StatusLightsAppStoreKind::Runs], true)) {
        throw new InvalidArgumentException('This app data record kind may not be pruned.');
    }

    $directory = status_lights_app_store_kind_directory($kind);
    if (!is_dir($directory)) {
        return 0;
    }

    try {
        $files = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
    } catch (UnexpectedValueException) {
        return 0;
    }

    $deleted = 0;

    foreach ($files as $file) {
        if (
            !$file->isFile()
            || strtolower($file->getExtension()) !== 'json'
            || preg_match($kind->keyPattern(), $file->getBasename('.json')) !== 1
        ) {
            continue;
        }

        $modifiedAt = @filemtime($file->getPathname());
        if (is_int($modifiedAt) && $modifiedAt < $cutoff && @unlink($file->getPathname())) {
            $deleted++;
        }
    }

    return $deleted;
}

function status_lights_app_prune_runs_older_than(int $cutoff): int
{
    return status_lights_app_prune_records_older_than(StatusLightsAppStoreKind::Runs, $cutoff);
}

function status_lights_app_prune_deliveries_older_than(int $cutoff): int
{
    return status_lights_app_prune_records_older_than(StatusLightsAppStoreKind::Deliveries, $cutoff);
}

/** @param resource|null $input */
function status_lights_app_read_webhook_body($input = null): string
{
    $maximumBytes = status_lights_app_max_webhook_bytes();
    $contentLength = $_SERVER['CONTENT_LENGTH'] ?? null;

    if (
        is_string($contentLength)
        && filter_var($contentLength, FILTER_VALIDATE_INT) !== false
        && (int) $contentLength > $maximumBytes
    ) {
        throw new StatusLightsPayloadTooLargeException('Webhook payload is too large.');
    }

    $closeInput = false;
    if ($input === null) {
        $input = @fopen('php://input', 'rb');
        $closeInput = true;
    }

    if (!is_resource($input)) {
        throw new RuntimeException('Unable to read webhook payload.');
    }

    $body = stream_get_contents($input, $maximumBytes + 1);
    if ($closeInput) {
        fclose($input);
    }

    if (!is_string($body)) {
        throw new RuntimeException('Unable to read webhook payload.');
    }

    if (strlen($body) > $maximumBytes) {
        throw new StatusLightsPayloadTooLargeException('Webhook payload is too large.');
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
        $parts[] = 'job';
        $parts[] = $job;
    }
    return status_lights_app_key(...$parts);
}

function status_lights_app_normalize_delivery_id(mixed $value): ?string
{
    if (
        !is_string($value)
        || preg_match(
            '/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/iD',
            $value,
        ) !== 1
    ) {
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

function status_lights_app_claim_delivery(string $deliveryId): bool
{
    return status_lights_app_create(
        StatusLightsAppStoreKind::Deliveries,
        status_lights_app_delivery_key($deliveryId),
        ['received_at' => time()],
    );
}

function status_lights_app_release_delivery(string $deliveryId): void
{
    status_lights_app_delete(
        StatusLightsAppStoreKind::Deliveries,
        status_lights_app_delivery_key($deliveryId),
    );
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

    status_lights_app_write(
        StatusLightsAppStoreKind::Repositories,
        status_lights_app_repo_key($owner, $name),
        [
            'owner' => $owner,
            'repository' => $name,
            'default_branch' => is_string($repository['default_branch'] ?? null)
                ? $repository['default_branch']
                : null,
            'installation_id' => $installationId,
            'installed' => $installed,
            'updated_at' => time(),
        ],
    );
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

    if (!is_string($owner) || !is_string($name) || !is_string($path) || !is_int($runId) || $runId < 1) {
        return;
    }

    if (is_string($defaultBranch) && is_string($headBranch) && $headBranch !== $defaultBranch) {
        return;
    }

    $workflow = basename($path);
    $state = status_lights_map_run_state($run);
    $now = time();

    status_lights_app_write(StatusLightsAppStoreKind::Runs, (string) $runId, [
        'owner' => $owner,
        'repository' => $name,
        'workflow' => $workflow,
        'updated_at' => $now,
    ]);
    status_lights_app_write(
        StatusLightsAppStoreKind::Statuses,
        status_lights_app_status_key($owner, $name, $workflow),
        [
            'state' => $state->value,
            'updated_at' => $now,
            'run_id' => $runId,
        ],
    );
    status_lights_app_mark_repository(
        $repository,
        is_int($payload['installation']['id'] ?? null)
            ? $payload['installation']['id']
            : null,
        true,
    );
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

    if (!is_string($owner) || !is_string($name) || !is_string($jobName) || !is_int($runId) || $runId < 1) {
        return;
    }

    if (is_string($defaultBranch) && is_string($headBranch) && $headBranch !== $defaultBranch) {
        return;
    }

    $run = status_lights_app_read(StatusLightsAppStoreKind::Runs, (string) $runId);
    $workflow = $run['workflow'] ?? null;
    if (!is_string($workflow)) {
        return;
    }

    status_lights_app_write(
        StatusLightsAppStoreKind::Statuses,
        status_lights_app_status_key($owner, $name, $workflow, $jobName),
        [
            'state' => status_lights_map_run_state($job)->value,
            'updated_at' => time(),
            'run_id' => $runId,
        ],
    );
}

// Process response adapter; it terminates the PHP request.
// @codeCoverageIgnoreStart
function status_lights_app_handle_webhook(): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        status_lights_send_json(['error' => 'Method not allowed'], 405);
    }

    try {
        $body = status_lights_app_read_webhook_body();
    } catch (StatusLightsPayloadTooLargeException) {
        status_lights_send_json(['error' => 'Webhook payload is too large'], 413);
    } catch (RuntimeException) {
        status_lights_send_json(['error' => 'Unable to read webhook payload'], 400);
    }

    if (!status_lights_app_verify_signature($body)) {
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

    $eventHeader = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? null;
    $event = is_string($eventHeader) && preg_match('/\A[a-z0-9_]{1,64}\z/D', $eventHeader) === 1
        ? $eventHeader
        : null;
    if ($event === null) {
        status_lights_send_json(['error' => 'Invalid webhook event'], 400);
    }

    $deliveryId = status_lights_app_normalize_delivery_id(
        $_SERVER['HTTP_X_GITHUB_DELIVERY'] ?? null,
    );
    if ($deliveryId === null) {
        status_lights_send_json(['error' => 'Invalid webhook delivery ID'], 400);
    }

    try {
        $claimed = status_lights_app_claim_delivery($deliveryId);
    } catch (Throwable) {
        status_lights_send_json(['error' => 'Unable to record webhook delivery'], 500);
    }

    if (!$claimed) {
        status_lights_send_json([
            'status' => 'accepted',
            'event' => $event,
            'duplicate' => true,
        ], 202);
    }

    if ($event === 'ping') {
        status_lights_send_json(['status' => 'ok', 'event' => 'ping']);
    }

    try {
        if ($event === 'installation' || $event === 'installation_repositories') {
            status_lights_app_handle_installation($event, $payload);
        } elseif ($event === 'workflow_run') {
            status_lights_app_handle_workflow_run($payload);
        } elseif ($event === 'workflow_job') {
            status_lights_app_handle_workflow_job($payload);
        }
    } catch (Throwable) {
        try {
            status_lights_app_release_delivery($deliveryId);
        } catch (Throwable) {
            // Preserve the processing failure as the response reason.
        }

        status_lights_send_json(['error' => 'Unable to process webhook delivery'], 500);
    }

    status_lights_send_json(['status' => 'accepted', 'event' => $event], 202);
}

/** @return array{state: StatusLightState, cache_status: string, fetched_at: int} */
// @codeCoverageIgnoreEnd
function status_lights_app_resolve(LightRequest $request): array
{
    $repo = status_lights_app_read(
        StatusLightsAppStoreKind::Repositories,
        status_lights_app_repo_key($request->owner, $request->repository),
    );
    $installed = ($repo['installed'] ?? false) === true;
    $status = status_lights_app_read(
        StatusLightsAppStoreKind::Statuses,
        status_lights_app_status_key(
            $request->owner,
            $request->repository,
            $request->workflow,
            $request->job,
        ),
    );
    $state = is_string($status['state'] ?? null)
        ? StatusLightState::tryFrom($status['state'])
        : null;

    if (!$installed || $state === null) {
        return [
            'state' => StatusLightState::Unknown,
            'cache_status' => $installed ? 'app-empty' : 'not-installed',
            'fetched_at' => time(),
        ];
    }

    return [
        'state' => $state,
        'cache_status' => 'webhook',
        'fetched_at' => is_int($status['updated_at'] ?? null) ? $status['updated_at'] : time(),
    ];
}

// Front controller; exercised by endpoint smoke tests.
// @codeCoverageIgnoreStart
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

// @codeCoverageIgnoreEnd
if (!defined('STATUS_LIGHTS_APP_TESTING')) {
    status_lights_app_main();
}
