<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

enum StatusLightState: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Running = 'running';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Success',
            self::Failure => 'Failure',
            self::Running => 'Running',
            self::Unknown => 'Unknown',
        };
    }

    public function defaultColor(): string
    {
        return match ($this) {
            self::Success => '1a7f37',
            self::Failure => 'cf222e',
            self::Running => 'bf8700',
            self::Unknown => '6e7781',
        };
    }
}

final readonly class LightRequest
{
    /**
     * @param array<string, string> $colors
     */
    public function __construct(
        public string $owner,
        public string $repository,
        public string $workflow,
        public ?string $job,
        public int $height,
        public ?int $width,
        public string $font,
        public int $fontSize,
        public int $radius,
        public string $text,
        public array $colors,
    ) {
    }

    public function color(StatusLightState $state): string
    {
        return $this->colors[$state->value];
    }
}

final class StatusLightsRouteException extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 400)
    {
        parent::__construct($message);
    }
}

final readonly class StatusLightsResponse
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
    ) {
    }
}

interface StatusLightsSystem
{
    public function getenv(string $name): string;
    public function time(): int;
    public function readInput(int $maxBytes): string;
    /**
     * @phpstan-impure
     */
    public function isDir(string $path): bool;
    public function isFile(string $path): bool;
    public function isWritable(string $path): bool;
    public function mkdir(string $path, int $permissions, bool $recursive): bool;
    public function fileGetContents(string $path): string|false;
    public function filePutContents(string $path, string $data, int $flags = 0): int|false;
    public function rename(string $from, string $to): bool;
    public function unlink(string $path): bool;
    public function chmod(string $path, int $permissions): bool;
    public function tempnam(string $dir, string $prefix): string|false;
    public function filemtime(string $path): int|false;
    public function createAtomicFile(string $path, string $contents): ?bool;
    /** @return iterable<string, string> */
    public function getJsonFilesInDirectory(string $path): iterable;

    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    public function fetchHttpJson(string $url, array $headers, int $timeout): array;
    public function extensionLoaded(string $name): bool;
}

final class StatusLightsRealSystem implements StatusLightsSystem
{
    public function getenv(string $name): string
    {
        $val = getenv($name);
        return is_string($val) ? $val : '';
    }

    public function time(): int { return time(); }

    public function readInput(int $maxBytes): string
    {
        $input = @fopen('php://input', 'rb');
        if (!is_resource($input)) return '';
        $body = stream_get_contents($input, $maxBytes + 1);
        fclose($input);
        return is_string($body) ? $body : '';
    }

    public function isDir(string $path): bool { return is_dir($path); }
    public function isFile(string $path): bool { return is_file($path); }
    public function isWritable(string $path): bool { return is_writable($path); }
    public function mkdir(string $path, int $permissions, bool $recursive): bool { return @mkdir($path, $permissions, $recursive); }
    public function fileGetContents(string $path): string|false { return @file_get_contents($path); }
    public function filePutContents(string $path, string $data, int $flags = 0): int|false { return @file_put_contents($path, $data, $flags); }
    public function rename(string $from, string $to): bool { return @rename($from, $to); }
    public function unlink(string $path): bool { return @unlink($path); }
    public function chmod(string $path, int $permissions): bool { return @chmod($path, $permissions); }
    public function tempnam(string $dir, string $prefix): string|false { return @tempnam($dir, $prefix); }
    public function filemtime(string $path): int|false { return @filemtime($path); }
    public function extensionLoaded(string $name): bool { return extension_loaded($name); }

    public function createAtomicFile(string $path, string $contents): ?bool
    {
        if (file_exists($path)) return false;
        $handle = @fopen($path, 'x+b');
        if (!is_resource($handle)) return null;
        try {
            $success = fwrite($handle, $contents) === strlen($contents) && fflush($handle);
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($path);
            throw $e;
        }
        fclose($handle);
        if (!$success) {
            @unlink($path);
            return null;
        }
        return true;
    }

    /** @return iterable<string, string> */
    public function getJsonFilesInDirectory(string $path): iterable
    {
        try {
            $files = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
            foreach ($files as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'json') {
                    yield $file->getPathname() => $file->getBasename('.json');
                }
            }
        } catch (UnexpectedValueException) {
            return;
        }
    }

    // @codeCoverageIgnoreStart
    // Justification: Requires external network access to the GitHub REST API. Cannot be cleanly mocked at the PHP level without an external dependency like Guzzle MockHandler.
    /**
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    public function fetchHttpJson(string $url, array $headers, int $timeout): array
    {
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Unable to initialize the GitHub request.');
        curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body)) throw new RuntimeException('GitHub request failed: ' . ($error ?: 'unknown error'));
        if ($statusCode !== 200) throw new RuntimeException(sprintf('GitHub returned HTTP %d.', $statusCode));

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('GitHub returned invalid JSON.', previous: $exception);
        }

        if (!is_array($payload)) throw new RuntimeException('GitHub returned an unexpected response.');
        return $payload;
    }
    // @codeCoverageIgnoreEnd
}

function status_lights_environment(string $name, ?StatusLightsSystem $system = null): string
{
    $system ??= new StatusLightsRealSystem();
    $value = $system->getenv($name);
    return trim($value);
}

function status_lights_environment_integer(string $name, int $default, int $minimum, int $maximum, ?StatusLightsSystem $system = null): int 
{
    $system ??= new StatusLightsRealSystem();
    $value = status_lights_environment($name, $system);
    if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) return $default;
    return min(max((int) $value, $minimum), $maximum);
}

/**
 * @return array{
 *     cache_directory: string,
 *     cache_ttl: int,
 *     stale_ttl: int,
 *     http_cache_ttl: int,
 *     github_timeout: int,
 *     github_token: string|null
 * }
 */
function status_lights_config(StatusLightsSystem $system): array
{
    $token = status_lights_environment('STATUS_LIGHTS_GITHUB_TOKEN', $system);
    if ($token === '') $token = status_lights_environment('GITHUB_TOKEN', $system);

    return [
        'cache_directory' => status_lights_environment('STATUS_LIGHTS_CACHE_DIR', $system) ?: __DIR__ . '/cache',
        'cache_ttl' => status_lights_environment_integer('STATUS_LIGHTS_CACHE_TTL', 60, 10, 3600, $system),
        'stale_ttl' => status_lights_environment_integer('STATUS_LIGHTS_STALE_TTL', 3600, 60, 86400, $system),
        'http_cache_ttl' => status_lights_environment_integer('STATUS_LIGHTS_HTTP_CACHE_TTL', 60, 0, 3600, $system),
        'github_timeout' => status_lights_environment_integer('STATUS_LIGHTS_GITHUB_TIMEOUT', 5, 1, 30, $system),
        'github_token' => $token !== '' ? $token : null,
    ];
}

function status_lights_parse_request(string $requestUri): LightRequest
{
    $path = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($path)) throw new StatusLightsRouteException('The request path is invalid.');
    if (strlen($path) > 2048) throw new StatusLightsRouteException('The request path may not exceed 2048 bytes.');

    $rawSegments = array_values(array_filter(
        explode('/', trim($path, '/')),
        static fn (string $segment): bool => $segment !== '',
    ));

    if (count($rawSegments) < 4 || $rawSegments[0] !== 'github') {
        throw new StatusLightsRouteException('Expected /github/{owner}/{repository}/{workflow}.svg or /github/{owner}/{repository}/{workflow}/job/{job}.svg.', 404);
    }

    $lastIndex = array_key_last($rawSegments);
    $lastSegment = $rawSegments[$lastIndex];

    if (!str_ends_with(strtolower($lastSegment), '.svg')) {
        throw new StatusLightsRouteException('Status light URLs must end in .svg.', 404);
    }

    $rawSegments[$lastIndex] = substr($lastSegment, 0, -4);
    $segments = array_map('rawurldecode', $rawSegments);
    $owner = $segments[1];
    $repository = status_lights_repository_segment($segments[2]);
    $workflow = $segments[3];

    status_lights_assert_not_dot_segment($owner, 'GitHub owner');
    status_lights_assert_not_dot_segment($repository, 'repository');
    status_lights_assert_not_dot_segment($workflow, 'workflow');
    status_lights_assert_matches($owner, '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/', 'GitHub owner');
    status_lights_assert_matches($repository, '/^[A-Za-z0-9._-]{1,100}$/', 'repository');
    status_lights_assert_matches($workflow, '/^[A-Za-z0-9._-]{1,100}$/', 'workflow');

    $optionSegments = array_slice($segments, 4);
    $job = null;

    if (($optionSegments[0] ?? null) === 'job') {
        if (!isset($optionSegments[1]) || $optionSegments[1] === '') {
            throw new StatusLightsRouteException('A job selector must include a job name.');
        }
        $job = status_lights_job_option($optionSegments[1]);
        $optionSegments = array_slice($optionSegments, 2);
    }

    if (count($optionSegments) % 2 !== 0) {
        throw new StatusLightsRouteException('Every option name must be followed by a value.');
    }

    $allowedOptions = ['size', 'width', 'font', 'font-size', 'radius', 'text', 'success-color', 'failure-color', 'running-color', 'unknown-color'];
    $options = [];

    for ($index = 0; $index < count($optionSegments); $index += 2) {
        $name = strtolower($optionSegments[$index]);
        $value = $optionSegments[$index + 1];

        if (!in_array($name, $allowedOptions, true)) throw new StatusLightsRouteException(sprintf('Unknown option: %s.', $name));
        if (array_key_exists($name, $options)) throw new StatusLightsRouteException(sprintf('Option %s may only appear once.', $name));
        $options[$name] = $value;
    }

    $height = status_lights_integer_option($options, 'size', 40, 16, 100);
    $width = array_key_exists('width', $options) ? status_lights_integer_option($options, 'width', $height, 16, 1000) : null;
    $font = strtolower($options['font'] ?? 'sans');

    if (!in_array($font, ['sans', 'mono', 'serif'], true)) {
        throw new StatusLightsRouteException('Font must be sans, mono, or serif.');
    }

    $fontSize = status_lights_integer_option($options, 'font-size', 16, 8, min(96, $height - 2));
    $radius = status_lights_integer_option($options, 'radius', 6, 0, intdiv($height, 2));
    $text = status_lights_text_option($options['text'] ?? '');
    $colors = [];

    foreach (StatusLightState::cases() as $state) {
        $name = $state->value . '-color';
        $colors[$state->value] = $state->defaultColor();
        if (array_key_exists($name, $options)) {
            $colors[$state->value] = status_lights_color_option($options[$name], $name);
        }
    }

    return new LightRequest(
        owner: $owner, repository: $repository, workflow: $workflow, job: $job,
        height: $height, width: $width, font: $font, fontSize: $fontSize,
        radius: $radius, text: $text, colors: $colors,
    );
}

function status_lights_repository_segment(string $value): string { return str_starts_with($value, '@.') ? substr($value, 1) : $value; }
function status_lights_job_option(string $value): string {
    $value = rawurldecode($value);
    if (preg_match('//u', $value) !== 1) throw new StatusLightsRouteException('Job name must be valid UTF-8.');
    if (preg_match('/[\\x00-\\x1F\\x7F]/u', $value) === 1) throw new StatusLightsRouteException('Job name may not contain control characters.');
    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if ($characters === false || count($characters) > 100) throw new StatusLightsRouteException('Job name may not exceed 100 characters.');
    return $value;
}
/** @param array<string, string> $options */
function status_lights_integer_option(array $options, string $name, int $default, int $minimum, int $maximum): int {
    if (!array_key_exists($name, $options)) return min(max($default, $minimum), $maximum);
    $value = filter_var($options[$name], FILTER_VALIDATE_INT);
    if ($value === false || $value < $minimum || $value > $maximum) throw new StatusLightsRouteException(sprintf('Option %s must be an integer from %d to %d.', $name, $minimum, $maximum));
    return $value;
}
function status_lights_text_option(string $value): string {
    $value = rawurldecode($value);
    if (preg_match('//u', $value) !== 1) throw new StatusLightsRouteException('Text must be valid UTF-8.');
    if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) throw new StatusLightsRouteException('Text may not contain control characters.');
    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if ($characters === false || count($characters) > 80) throw new StatusLightsRouteException('Text may not exceed 80 characters.');
    return $value;
}
function status_lights_color_option(string $value, string $name): string {
    $normalized = strtolower(ltrim($value, '#'));
    if (preg_match('/^[0-9a-f]{6}$/', $normalized) !== 1) throw new StatusLightsRouteException(sprintf('Option %s must be a six-digit hexadecimal colour.', $name));
    return $normalized;
}
function status_lights_assert_matches(string $value, string $pattern, string $name): void {
    if (preg_match($pattern, $value) !== 1) throw new StatusLightsRouteException(sprintf('Invalid %s.', $name));
}
function status_lights_assert_not_dot_segment(string $value, string $name): void {
    if ($value === '.' || $value === '..') throw new StatusLightsRouteException(sprintf('Invalid %s.', $name));
}

/** @param array<string, mixed> $run */
function status_lights_map_run_state(array $run): StatusLightState {
    $status = strtolower((string) ($run['status'] ?? ''));
    if ($status !== '' && $status !== 'completed') return StatusLightState::Running;
    $conclusion = strtolower((string) ($run['conclusion'] ?? ''));
    return match ($conclusion) {
        'success' => StatusLightState::Success,
        'failure', 'cancelled', 'timed_out', 'action_required', 'startup_failure', 'stale' => StatusLightState::Failure,
        default => StatusLightState::Unknown,
    };
}

/**
 * @param array{
 *     cache_directory: string,
 *     cache_ttl: int,
 *     stale_ttl: int,
 *     http_cache_ttl: int,
 *     github_timeout: int,
 *     github_token: string|null
 * } $config
 */
function status_lights_fetch_state(StatusLightsSystem $system, string $owner, string $repository, string $workflow, array $config, ?string $job = null): StatusLightState {
    $payload = status_lights_fetch_runs($system, $owner, $repository, $workflow, $config);
    $run = $payload['workflow_runs'][0] ?? null;
    if (!is_array($run)) return StatusLightState::Unknown;
    $defaultBranch = $run['repository']['default_branch'] ?? null;
    $headBranch = $run['head_branch'] ?? null;

    if (is_string($defaultBranch) && $defaultBranch !== '' && $headBranch !== $defaultBranch) {
        $payload = status_lights_fetch_runs($system, $owner, $repository, $workflow, $config, $defaultBranch);
        $run = $payload['workflow_runs'][0] ?? null;
    }

    if (!is_array($run)) return StatusLightState::Unknown;
    if ($job === null) return status_lights_map_run_state($run);
    $runId = $run['id'] ?? null;
    if (!is_int($runId)) return StatusLightState::Unknown;
    $jobs = status_lights_fetch_jobs($system, $owner, $repository, $runId, $config);
    return status_lights_find_job_state($jobs, $job);
}

/** @param array<string, mixed> $payload */
function status_lights_find_job_state(array $payload, string $jobName): StatusLightState {
    $jobs = $payload['jobs'] ?? null;
    if (!is_array($jobs)) return StatusLightState::Unknown;
    foreach ($jobs as $job) {
        if (is_array($job) && ($job['name'] ?? null) === $jobName) return status_lights_map_run_state($job);
    }
    return StatusLightState::Unknown;
}

/**
 * @param array{
 *     cache_directory: string,
 *     cache_ttl: int,
 *     stale_ttl: int,
 *     http_cache_ttl: int,
 *     github_timeout: int,
 *     github_token: string|null
 * } $config
 * @return array<string, mixed>
 */
function status_lights_fetch_runs(StatusLightsSystem $system, string $owner, string $repository, string $workflow, array $config, ?string $branch = null): array {
    $query = ['per_page' => '1'];
    if ($branch !== null) $query['branch'] = $branch;
    $url = sprintf('https://api.github.com/repos/%s/%s/actions/workflows/%s/runs?%s', rawurlencode($owner), rawurlencode($repository), rawurlencode($workflow), http_build_query($query, encoding_type: PHP_QUERY_RFC3986));
    return status_lights_fetch_github_json($system, $url, $config);
}

/**
 * @param array{
 *     cache_directory: string,
 *     cache_ttl: int,
 *     stale_ttl: int,
 *     http_cache_ttl: int,
 *     github_timeout: int,
 *     github_token: string|null
 * } $config
 * @return array<string, mixed>
 */
function status_lights_fetch_jobs(StatusLightsSystem $system, string $owner, string $repository, int $runId, array $config): array {
    $url = sprintf('https://api.github.com/repos/%s/%s/actions/runs/%d/jobs?%s', rawurlencode($owner), rawurlencode($repository), $runId, http_build_query(['filter' => 'latest', 'per_page' => '100'], encoding_type: PHP_QUERY_RFC3986));
    return status_lights_fetch_github_json($system, $url, $config);
}

/**
 * @param array{
 *     cache_directory: string,
 *     cache_ttl: int,
 *     stale_ttl: int,
 *     http_cache_ttl: int,
 *     github_timeout: int,
 *     github_token: string|null
 * } $config
 * @return array<string, mixed>
 */
function status_lights_fetch_github_json(StatusLightsSystem $system, string $url, array $config): array {
    $headers = ['Accept: application/vnd.github+json', 'User-Agent: statuslights.dev', 'X-GitHub-Api-Version: 2022-11-28'];
    if (is_string($config['github_token'])) $headers[] = 'Authorization: Bearer ' . $config['github_token'];
    return $system->fetchHttpJson($url, $headers, (int) $config['github_timeout']);
}

/**
 * @param array{
 *     cache_directory: string,
 *     cache_ttl: int,
 *     stale_ttl: int,
 *     http_cache_ttl: int,
 *     github_timeout: int,
 *     github_token: string|null
 * } $config
 * @return array{state: StatusLightState, cache_status: string, fetched_at: int}
 */
function status_lights_resolve_state(StatusLightsSystem $system, LightRequest $request, array $config, ?callable $provider = null, ?int $now = null): array {
    $now ??= $system->time();
    $keyParts = [strtolower($request->owner), strtolower($request->repository), $request->workflow];
    if ($request->job !== null) { $keyParts[] = 'job'; $keyParts[] = $request->job; }
    $key = implode('/', $keyParts);
    $cacheDirectory = (string) $config['cache_directory'];
    $cached = status_lights_read_cache($system, $cacheDirectory, $key);

    if ($cached !== null && ($now - $cached['fetched_at']) <= (int) $config['cache_ttl']) {
        return ['state' => $cached['state'], 'cache_status' => 'hit', 'fetched_at' => $cached['fetched_at']];
    }

    $provider ??= static fn (string $owner, string $repository, string $workflow, ?string $job): StatusLightState => status_lights_fetch_state($system, $owner, $repository, $workflow, $config, $job);

    try {
        $state = $provider($request->owner, $request->repository, $request->workflow, $request->job);
        if (!$state instanceof StatusLightState) throw new RuntimeException('The provider returned an unsupported state.');
        status_lights_write_cache($system, $cacheDirectory, $key, $state, $now);
        return ['state' => $state, 'cache_status' => 'miss', 'fetched_at' => $now];
    } catch (Throwable) {
        if ($cached !== null && ($now - $cached['fetched_at']) <= (int) $config['stale_ttl']) {
            return ['state' => $cached['state'], 'cache_status' => 'stale', 'fetched_at' => $cached['fetched_at']];
        }
        return ['state' => StatusLightState::Unknown, 'cache_status' => 'error', 'fetched_at' => $now];
    }
}

/** @return array{state: StatusLightState, fetched_at: int}|null */
function status_lights_read_cache(StatusLightsSystem $system, string $directory, string $key): ?array {
    $path = status_lights_cache_path($directory, $key);
    if (!$system->isFile($path)) return null;
    $contents = $system->fileGetContents($path);
    if (!is_string($contents)) return null;
    try {
        $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException) { return null; }
    if (!is_array($value) || !is_string($value['state'] ?? null) || !is_int($value['fetched_at'] ?? null)) return null;
    $state = StatusLightState::tryFrom($value['state']);
    return $state === null ? null : ['state' => $state, 'fetched_at' => $value['fetched_at']];
}

function status_lights_write_cache(StatusLightsSystem $system, string $directory, string $key, StatusLightState $state, int $fetchedAt): void {
    if (!$system->isDir($directory) && !$system->mkdir($directory, 0755, true) && !$system->isDir($directory)) return;
    $contents = json_encode(['state' => $state->value, 'fetched_at' => $fetchedAt], JSON_THROW_ON_ERROR);
    $temporaryPath = $system->tempnam($directory, 'status-light-');
    if (!is_string($temporaryPath)) return;
    if ($system->filePutContents($temporaryPath, $contents, LOCK_EX) === false) {
        $system->unlink($temporaryPath);
        return;
    }
    $system->chmod($temporaryPath, 0644);
    if (!$system->rename($temporaryPath, status_lights_cache_path($directory, $key))) {
        $system->unlink($temporaryPath);
    }
}

function status_lights_cache_path(string $directory, string $key): string {
    return rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
}

/** @param array{state: StatusLightState, cache_status: string, fetched_at: int} $result */
function status_lights_render_svg(LightRequest $request, array $result): string {
    $fonts = ['sans' => 'Arial, Helvetica, sans-serif', 'mono' => 'ui-monospace, SFMono-Regular, Consolas, monospace', 'serif' => "Georgia, 'Times New Roman', serif"];
    $state = $result['state'];
    $statusLabel = $state->label();
    $label = str_replace('{status}', $statusLabel, $request->text);
    $width = $request->width !== null ? $request->width : status_lights_automatic_width($label, $request->height, $request->fontSize);
    $color = $request->color($state);
    $background = '#' . $color;
    $foreground = status_lights_contrast_color($color);
    $target = $request->job !== null ? sprintf('%s job %s', $request->workflow, $request->job) : $request->workflow;
    $title = sprintf('%s/%s %s status: %s', $request->owner, $request->repository, $target, $statusLabel);
    
    $svg = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-labelledby="title" data-state="%s">', $width, $request->height, $width, $request->height, $state->value),
        '<title id="title">' . status_lights_escape($title) . '</title>',
        sprintf('<rect width="%d" height="%d" rx="%d" fill="%s"/>', $width, $request->height, $request->radius, $background),
    ];

    if ($label !== '') {
        $svg[] = sprintf('<text x="50%%" y="50%%" fill="%s" font-family="%s" font-size="%d" text-anchor="middle" dominant-baseline="central">%s</text>', $foreground, status_lights_escape($fonts[$request->font]), $request->fontSize, status_lights_escape($label));
    }
    $svg[] = '</svg>';
    return implode('', $svg);
}

function status_lights_render_error(string $message): string {
    $safeMessage = status_lights_escape($message);
    return '<?xml version="1.0" encoding="UTF-8"?>' . '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="40" viewBox="0 0 240 40" role="img" aria-labelledby="title">' . '<title id="title">' . $safeMessage . '</title>' . '<rect width="240" height="40" rx="6" fill="#6e7781"/>' . '<text x="120" y="20" fill="#ffffff" font-family="Arial, Helvetica, sans-serif" font-size="14" text-anchor="middle" dominant-baseline="central">' . $safeMessage . '</text></svg>';
}

function status_lights_automatic_width(string $label, int $height, int $fontSize): int {
    if ($label === '') return $height;
    $characters = preg_split('//u', $label, -1, PREG_SPLIT_NO_EMPTY);
    $characterCount = is_array($characters) ? count($characters) : strlen($label);
    $padding = (int) ceil($height * 0.28);
    return max($height, (int) ceil(($characterCount * $fontSize * 0.64) + ($padding * 2)));
}

function status_lights_contrast_color(string $hex): string {
    $channels = [];
    foreach ([0, 2, 4] as $offset) {
        $channel = hexdec(substr($hex, $offset, 2)) / 255;
        $channels[] = $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4;
    }
    $luminance = (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    $whiteContrast = 1.05 / ($luminance + 0.05);
    $blackContrast = ($luminance + 0.05) / 0.05;
    return $whiteContrast >= $blackContrast ? '#ffffff' : '#000000';
}

function status_lights_escape(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * @param array{state: StatusLightState, cache_status: string, fetched_at: int}|null $result
 * @param array<string, mixed> $server
 */
function status_lights_create_svg_response(string $body, int $statusCode, int $cacheTtl, ?array $result = null, array $server = []): StatusLightsResponse {
    $etag = '"' . hash('sha256', $body) . '"';
    if (($server['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        return new StatusLightsResponse(304, ['ETag' => $etag], '');
    }
    $headers = [
        'Content-Type' => 'image/svg+xml; charset=utf-8',
        'Cache-Control' => "public, max-age=$cacheTtl, stale-if-error=300",
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        'Cross-Origin-Resource-Policy' => 'cross-origin',
        'Access-Control-Allow-Origin' => '*',
        'X-Content-Type-Options' => 'nosniff',
        'ETag' => $etag,
    ];
    if ($result !== null) {
        $headers['X-Status-Lights-State'] = $result['state']->value;
        $headers['X-Status-Lights-Cache'] = $result['cache_status'];
    }
    return new StatusLightsResponse($statusCode, $headers, $body);
}

/**
 * @param array<string, mixed> $body
 * @param array<string, string> $headers
 */
function status_lights_create_json_response(array $body, int $statusCode = 200, array $headers = []): StatusLightsResponse {
    $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $mergedHeaders = array_merge([
        'Content-Type' => 'application/json; charset=utf-8',
        'Cache-Control' => 'no-store',
        'Access-Control-Allow-Origin' => '*',
        'X-Content-Type-Options' => 'nosniff',
    ], $headers);
    return new StatusLightsResponse($statusCode, $mergedHeaders, $encoded);
}

/** @param array<string, mixed> $server */
function status_lights_handle_legacy_request(StatusLightsSystem $system, array $server): StatusLightsResponse {
    $path = parse_url($server['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($path === '/') return new StatusLightsResponse(302, ['Location' => 'https://statuslights.dev/'], '');

    $config = status_lights_config($system);

    if ($path === '/health') {
        $cacheDirectory = (string) $config['cache_directory'];
        $cacheParent = dirname($cacheDirectory);
        $cacheWritable = $system->isDir($cacheDirectory) ? $system->isWritable($cacheDirectory) : ($system->isDir($cacheParent) && $system->isWritable($cacheParent));
        $checks = ['curl' => $system->extensionLoaded('curl'), 'cache_writable' => $cacheWritable];
        $healthy = !in_array(false, $checks, true);
        return status_lights_create_json_response(['status' => $healthy ? 'ok' : 'degraded', 'service' => 'status-lights-generator', 'checks' => $checks], $healthy ? 200 : 503);
    }

    try {
        $request = status_lights_parse_request($server['REQUEST_URI'] ?? '/');
        $result = status_lights_resolve_state($system, $request, $config);
        return status_lights_create_svg_response(status_lights_render_svg($request, $result), 200, (int) $config['http_cache_ttl'], $result, $server);
    } catch (StatusLightsRouteException $exception) {
        return status_lights_create_svg_response(status_lights_render_error($exception->getMessage()), $exception->statusCode, 0, null, $server);
    } catch (Throwable) {
        return status_lights_create_svg_response(status_lights_render_error('Status temporarily unavailable'), 500, 0, null, $server);
    }
}

// @codeCoverageIgnoreStart
// Justification: Interacts directly with the PHP SAPI. Cannot be intercepted cleanly in standard PHPUnit without external tools.
function status_lights_emit_response(StatusLightsResponse $response): void {
    http_response_code($response->statusCode);
    foreach ($response->headers as $name => $value) {
        header(sprintf('%s: %s', $name, $value));
    }
    echo $response->body;
}
// @codeCoverageIgnoreEnd

function status_lights_main(): void {
    $system = new StatusLightsRealSystem();
    $response = status_lights_handle_legacy_request($system, $_SERVER);
    
    // @codeCoverageIgnoreStart
    status_lights_emit_response($response);
    exit;
    // @codeCoverageIgnoreEnd
}

if (!defined('STATUS_LIGHTS_TESTING')) {
    // @codeCoverageIgnoreStart
    status_lights_main();
    // @codeCoverageIgnoreEnd
}