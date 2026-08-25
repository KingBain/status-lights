<?php

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

const STATUS_LIGHTS_SUCCESS = 'success';
const STATUS_LIGHTS_FAILURE = 'failure';
const STATUS_LIGHTS_RUNNING = 'running';
const STATUS_LIGHTS_UNKNOWN = 'unknown';

const STATUS_LIGHTS_STATES = [
    STATUS_LIGHTS_SUCCESS,
    STATUS_LIGHTS_FAILURE,
    STATUS_LIGHTS_RUNNING,
    STATUS_LIGHTS_UNKNOWN,
];

const STATUS_LIGHTS_DEFAULT_COLORS = [
    STATUS_LIGHTS_SUCCESS => '1a7f37',
    STATUS_LIGHTS_FAILURE => 'cf222e',
    STATUS_LIGHTS_RUNNING => 'bf8700',
    STATUS_LIGHTS_UNKNOWN => '6e7781',
];

final class StatusLightsRouteException extends RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode = 400)
    {
        parent::__construct($message);
    }
}

/** @return array<string, int|string|null> */
function status_lights_config(): array
{
    $token = status_lights_environment('STATUS_LIGHTS_GITHUB_TOKEN');

    if ($token === '') {
        $token = status_lights_environment('GITHUB_TOKEN');
    }

    return [
        'cache_directory' => status_lights_environment('STATUS_LIGHTS_CACHE_DIR')
            ?: __DIR__ . '/cache',
        'cache_ttl' => status_lights_environment_integer('STATUS_LIGHTS_CACHE_TTL', 60, 10, 3600),
        'stale_ttl' => status_lights_environment_integer('STATUS_LIGHTS_STALE_TTL', 3600, 60, 86400),
        'http_cache_ttl' => status_lights_environment_integer(
            'STATUS_LIGHTS_HTTP_CACHE_TTL',
            60,
            0,
            3600,
        ),
        'github_timeout' => status_lights_environment_integer(
            'STATUS_LIGHTS_GITHUB_TIMEOUT',
            5,
            1,
            30,
        ),
        'github_token' => $token !== '' ? $token : null,
    ];
}

function status_lights_environment(string $name): string
{
    $value = getenv($name);

    return is_string($value) ? trim($value) : '';
}

function status_lights_environment_integer(
    string $name,
    int $default,
    int $minimum,
    int $maximum,
): int {
    $value = status_lights_environment($name);

    if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
        return $default;
    }

    return min(max((int) $value, $minimum), $maximum);
}

/**
 * @return array{
 *   owner: string,
 *   repository: string,
 *   workflow: string,
 *   job: string|null,
 *   height: int,
 *   width: int|null,
 *   font: string,
 *   font_size: int,
 *   radius: int,
 *   text: string,
 *   colors: array<string, string>
 * }
 */
function status_lights_parse_request(string $requestUri): array
{
    $path = parse_url($requestUri, PHP_URL_PATH);

    if (!is_string($path)) {
        throw new StatusLightsRouteException('The request path is invalid.');
    }

    if (strlen($path) > 2048) {
        throw new StatusLightsRouteException('The request path may not exceed 2048 bytes.');
    }

    $rawSegments = array_values(array_filter(
        explode('/', trim($path, '/')),
        static fn (string $segment): bool => $segment !== '',
    ));

    if (count($rawSegments) < 4 || $rawSegments[0] !== 'github') {
        throw new StatusLightsRouteException(
            'Expected /github/{owner}/{repository}/{workflow}.svg or '
                . '/github/{owner}/{repository}/{workflow}/job/{job}.svg.',
            404,
        );
    }

    $lastIndex = array_key_last($rawSegments);
    $lastSegment = $rawSegments[$lastIndex];

    if (!str_ends_with(strtolower($lastSegment), '.svg')) {
        throw new StatusLightsRouteException('Status light URLs must end in .svg.', 404);
    }

    $rawSegments[$lastIndex] = substr($lastSegment, 0, -4);
    $segments = array_map('rawurldecode', $rawSegments);
    $owner = $segments[1];
    $repository = $segments[2];
    $workflow = $segments[3];

    status_lights_assert_matches(
        $owner,
        '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/',
        'GitHub owner',
    );
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

    $allowedOptions = [
        'size',
        'width',
        'font',
        'font-size',
        'radius',
        'text',
        'success-color',
        'failure-color',
        'running-color',
        'unknown-color',
    ];
    $options = [];

    for ($index = 0; $index < count($optionSegments); $index += 2) {
        $name = strtolower($optionSegments[$index]);
        $value = $optionSegments[$index + 1];

        if (!in_array($name, $allowedOptions, true)) {
            throw new StatusLightsRouteException(sprintf('Unknown option: %s.', $name));
        }

        if (array_key_exists($name, $options)) {
            throw new StatusLightsRouteException(sprintf('Option %s may only appear once.', $name));
        }

        $options[$name] = $value;
    }

    $height = status_lights_integer_option($options, 'size', 40, 16, 100);
    $width = array_key_exists('width', $options)
        ? status_lights_integer_option($options, 'width', $height, 16, 1000)
        : null;
    $font = strtolower($options['font'] ?? 'sans');

    if (!in_array($font, ['sans', 'mono', 'serif'], true)) {
        throw new StatusLightsRouteException('Font must be sans, mono, or serif.');
    }

    $fontSize = status_lights_integer_option(
        $options,
        'font-size',
        16,
        8,
        min(96, $height - 2),
    );
    $radius = status_lights_integer_option($options, 'radius', 6, 0, intdiv($height, 2));
    $text = status_lights_text_option($options['text'] ?? '');
    $colors = STATUS_LIGHTS_DEFAULT_COLORS;

    foreach (STATUS_LIGHTS_STATES as $state) {
        $name = $state . '-color';

        if (array_key_exists($name, $options)) {
            $colors[$state] = status_lights_color_option($options[$name], $name);
        }
    }

    return [
        'owner' => $owner,
        'repository' => $repository,
        'workflow' => $workflow,
        'job' => $job,
        'height' => $height,
        'width' => $width,
        'font' => $font,
        'font_size' => $fontSize,
        'radius' => $radius,
        'text' => $text,
        'colors' => $colors,
    ];
}

function status_lights_job_option(string $value): string
{
    // Match text handling so encoded slashes survive Apache path processing.
    $value = rawurldecode($value);

    if (preg_match('//u', $value) !== 1) {
        throw new StatusLightsRouteException('Job name must be valid UTF-8.');
    }

    if (preg_match('/[\\x00-\\x1F\\x7F]/u', $value) === 1) {
        throw new StatusLightsRouteException('Job name may not contain control characters.');
    }

    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

    if ($characters === false || count($characters) > 100) {
        throw new StatusLightsRouteException('Job name may not exceed 100 characters.');
    }

    return $value;
}

/** @param array<string, string> $options */
function status_lights_integer_option(
    array $options,
    string $name,
    int $default,
    int $minimum,
    int $maximum,
): int {
    if (!array_key_exists($name, $options)) {
        return min(max($default, $minimum), $maximum);
    }

    $value = filter_var($options[$name], FILTER_VALIDATE_INT);

    if ($value === false || $value < $minimum || $value > $maximum) {
        throw new StatusLightsRouteException(sprintf(
            'Option %s must be an integer from %d to %d.',
            $name,
            $minimum,
            $maximum,
        ));
    }

    return $value;
}

function status_lights_text_option(string $value): string
{
    // The website double-encodes slashes so Apache cannot treat them as path separators.
    $value = rawurldecode($value);

    if (preg_match('//u', $value) !== 1) {
        throw new StatusLightsRouteException('Text must be valid UTF-8.');
    }

    if (preg_match('/[\x00-\x1F\x7F]/u', $value) === 1) {
        throw new StatusLightsRouteException('Text may not contain control characters.');
    }

    $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);

    if ($characters === false || count($characters) > 80) {
        throw new StatusLightsRouteException('Text may not exceed 80 characters.');
    }

    return $value;
}

function status_lights_color_option(string $value, string $name): string
{
    $normalized = strtolower(ltrim($value, '#'));

    if (preg_match('/^[0-9a-f]{6}$/', $normalized) !== 1) {
        throw new StatusLightsRouteException(sprintf(
            'Option %s must be a six-digit hexadecimal colour.',
            $name,
        ));
    }

    return $normalized;
}

function status_lights_assert_matches(string $value, string $pattern, string $name): void
{
    if (preg_match($pattern, $value) !== 1) {
        throw new StatusLightsRouteException(sprintf('Invalid %s.', $name));
    }
}

/** @param array<string, mixed> $run */
function status_lights_map_run_state(array $run): string
{
    $status = strtolower((string) ($run['status'] ?? ''));

    if ($status !== '' && $status !== 'completed') {
        return STATUS_LIGHTS_RUNNING;
    }

    $conclusion = strtolower((string) ($run['conclusion'] ?? ''));

    return match ($conclusion) {
        'success' => STATUS_LIGHTS_SUCCESS,
        'failure', 'cancelled', 'timed_out', 'action_required', 'startup_failure', 'stale'
            => STATUS_LIGHTS_FAILURE,
        default => STATUS_LIGHTS_UNKNOWN,
    };
}

/**
 * @param array<string, int|string|null> $config
 */
function status_lights_fetch_state(
    string $owner,
    string $repository,
    string $workflow,
    array $config,
    ?string $job = null,
): string {
    $payload = status_lights_fetch_runs($owner, $repository, $workflow, $config);
    $run = $payload['workflow_runs'][0] ?? null;

    if (!is_array($run)) {
        return STATUS_LIGHTS_UNKNOWN;
    }

    $defaultBranch = $run['repository']['default_branch'] ?? null;
    $headBranch = $run['head_branch'] ?? null;

    if (is_string($defaultBranch) && $defaultBranch !== '' && $headBranch !== $defaultBranch) {
        $payload = status_lights_fetch_runs(
            $owner,
            $repository,
            $workflow,
            $config,
            $defaultBranch,
        );
        $run = $payload['workflow_runs'][0] ?? null;
    }

    if (!is_array($run)) {
        return STATUS_LIGHTS_UNKNOWN;
    }

    if ($job === null) {
        return status_lights_map_run_state($run);
    }

    $runId = $run['id'] ?? null;

    if (!is_int($runId)) {
        return STATUS_LIGHTS_UNKNOWN;
    }

    $jobs = status_lights_fetch_jobs($owner, $repository, $runId, $config);

    return status_lights_find_job_state($jobs, $job);
}

/** @param array<string, mixed> $payload */
function status_lights_find_job_state(array $payload, string $jobName): string
{
    $jobs = $payload['jobs'] ?? null;

    if (!is_array($jobs)) {
        return STATUS_LIGHTS_UNKNOWN;
    }

    foreach ($jobs as $job) {
        if (is_array($job) && ($job['name'] ?? null) === $jobName) {
            return status_lights_map_run_state($job);
        }
    }

    return STATUS_LIGHTS_UNKNOWN;
}

/**
 * @param array<string, int|string|null> $config
 * @return array<string, mixed>
 */
function status_lights_fetch_runs(
    string $owner,
    string $repository,
    string $workflow,
    array $config,
    ?string $branch = null,
): array {
    $query = ['per_page' => '1'];

    if ($branch !== null) {
        $query['branch'] = $branch;
    }

    $url = sprintf(
        'https://api.github.com/repos/%s/%s/actions/workflows/%s/runs?%s',
        rawurlencode($owner),
        rawurlencode($repository),
        rawurlencode($workflow),
        http_build_query($query, encoding_type: PHP_QUERY_RFC3986),
    );
    $payload = status_lights_fetch_github_json($url, $config);

    if (
        !is_array($payload)
        || !isset($payload['workflow_runs'])
        || !is_array($payload['workflow_runs'])
    ) {
        throw new RuntimeException('GitHub returned an unexpected response.');
    }

    return $payload;
}

/**
 * @param array<string, int|string|null> $config
 * @return array<string, mixed>
 */
function status_lights_fetch_jobs(
    string $owner,
    string $repository,
    int $runId,
    array $config,
): array {
    $url = sprintf(
        'https://api.github.com/repos/%s/%s/actions/runs/%d/jobs?%s',
        rawurlencode($owner),
        rawurlencode($repository),
        $runId,
        http_build_query(
            ['filter' => 'latest', 'per_page' => '100'],
            encoding_type: PHP_QUERY_RFC3986,
        ),
    );
    $payload = status_lights_fetch_github_json($url, $config);

    if (!isset($payload['jobs']) || !is_array($payload['jobs'])) {
        throw new RuntimeException('GitHub returned an unexpected jobs response.');
    }

    return $payload;
}

/**
 * @param array<string, int|string|null> $config
 * @return array<string, mixed>
 */
function status_lights_fetch_github_json(string $url, array $config): array
{
    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: statuslights.dev',
        'X-GitHub-Api-Version: 2022-11-28',
    ];

    if (is_string($config['github_token'])) {
        $headers[] = 'Authorization: Bearer ' . $config['github_token'];
    }

    $handle = curl_init($url);

    if ($handle === false) {
        throw new RuntimeException('Unable to initialize the GitHub request.');
    }

    $timeout = (int) $config['github_timeout'];
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

    if (!is_string($body)) {
        throw new RuntimeException('GitHub request failed: ' . ($error ?: 'unknown error'));
    }

    if ($statusCode !== 200) {
        throw new RuntimeException(sprintf('GitHub returned HTTP %d.', $statusCode));
    }

    try {
        $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        throw new RuntimeException('GitHub returned invalid JSON.', previous: $exception);
    }

    if (!is_array($payload)) {
        throw new RuntimeException('GitHub returned an unexpected response.');
    }

    return $payload;
}

/**
 * @param array<string, mixed> $request
 * @param array<string, int|string|null> $config
 * @param callable(string, string, string, string|null): string|null $provider
 * @return array{state: string, cache_status: string, fetched_at: int}
 */
function status_lights_resolve_state(
    array $request,
    array $config,
    ?callable $provider = null,
    ?int $now = null,
): array {
    $now ??= time();
    $keyParts = [
        strtolower((string) $request['owner']),
        strtolower((string) $request['repository']),
        (string) $request['workflow'],
    ];

    if (is_string($request['job'])) {
        $keyParts[] = 'job';
        $keyParts[] = $request['job'];
    }

    $key = implode('/', $keyParts);
    $cacheDirectory = (string) $config['cache_directory'];
    $cached = status_lights_read_cache($cacheDirectory, $key);

    if ($cached !== null && ($now - $cached['fetched_at']) <= (int) $config['cache_ttl']) {
        return [
            'state' => $cached['state'],
            'cache_status' => 'hit',
            'fetched_at' => $cached['fetched_at'],
        ];
    }

    $provider ??= static fn (
        string $owner,
        string $repository,
        string $workflow,
        ?string $job,
    ): string => status_lights_fetch_state($owner, $repository, $workflow, $config, $job);

    try {
        $state = $provider(
            (string) $request['owner'],
            (string) $request['repository'],
            (string) $request['workflow'],
            is_string($request['job']) ? $request['job'] : null,
        );

        if (!in_array($state, STATUS_LIGHTS_STATES, true)) {
            throw new RuntimeException('The provider returned an unsupported state.');
        }

        status_lights_write_cache($cacheDirectory, $key, $state, $now);

        return ['state' => $state, 'cache_status' => 'miss', 'fetched_at' => $now];
    } catch (Throwable) {
        if ($cached !== null && ($now - $cached['fetched_at']) <= (int) $config['stale_ttl']) {
            return [
                'state' => $cached['state'],
                'cache_status' => 'stale',
                'fetched_at' => $cached['fetched_at'],
            ];
        }

        return ['state' => STATUS_LIGHTS_UNKNOWN, 'cache_status' => 'error', 'fetched_at' => $now];
    }
}

/** @return array{state: string, fetched_at: int}|null */
function status_lights_read_cache(string $directory, string $key): ?array
{
    $path = status_lights_cache_path($directory, $key);

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

    if (
        !is_array($value)
        || !isset($value['state'], $value['fetched_at'])
        || !in_array($value['state'], STATUS_LIGHTS_STATES, true)
        || !is_int($value['fetched_at'])
    ) {
        return null;
    }

    return ['state' => $value['state'], 'fetched_at' => $value['fetched_at']];
}

function status_lights_write_cache(
    string $directory,
    string $key,
    string $state,
    int $fetchedAt,
): void {
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
        return;
    }

    try {
        $contents = json_encode(
            ['state' => $state, 'fetched_at' => $fetchedAt],
            JSON_THROW_ON_ERROR,
        );
    } catch (JsonException) {
        return;
    }

    $temporaryPath = @tempnam($directory, 'status-light-');

    if (!is_string($temporaryPath)) {
        return;
    }

    if (@file_put_contents($temporaryPath, $contents, LOCK_EX) === false) {
        @unlink($temporaryPath);
        return;
    }

    @chmod($temporaryPath, 0644);

    if (!@rename($temporaryPath, status_lights_cache_path($directory, $key))) {
        @unlink($temporaryPath);
    }
}

function status_lights_cache_path(string $directory, string $key): string
{
    return rtrim($directory, DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR
        . hash('sha256', $key)
        . '.json';
}

/**
 * @param array<string, mixed> $request
 * @param array{state: string, cache_status: string, fetched_at: int} $result
 */
function status_lights_render_svg(array $request, array $result): string
{
    $labels = [
        STATUS_LIGHTS_SUCCESS => 'Success',
        STATUS_LIGHTS_FAILURE => 'Failure',
        STATUS_LIGHTS_RUNNING => 'Running',
        STATUS_LIGHTS_UNKNOWN => 'Unknown',
    ];
    $fonts = [
        'sans' => 'Arial, Helvetica, sans-serif',
        'mono' => 'ui-monospace, SFMono-Regular, Consolas, monospace',
        'serif' => "Georgia, 'Times New Roman', serif",
    ];
    $state = $result['state'];
    $statusLabel = $labels[$state];
    $label = str_replace('{status}', $statusLabel, (string) $request['text']);
    $width = is_int($request['width'])
        ? $request['width']
        : status_lights_automatic_width(
            $label,
            (int) $request['height'],
            (int) $request['font_size'],
        );
    $colors = $request['colors'];
    $color = (string) $colors[$state];
    $background = '#' . $color;
    $foreground = status_lights_contrast_color($color);
    $target = is_string($request['job'])
        ? sprintf('%s job %s', $request['workflow'], $request['job'])
        : (string) $request['workflow'];
    $title = sprintf(
        '%s/%s %s status: %s',
        $request['owner'],
        $request['repository'],
        $target,
        $statusLabel,
    );
    $svg = [
        '<?xml version="1.0" encoding="UTF-8"?>',
        sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-labelledby="title" data-state="%s">',
            $width,
            $request['height'],
            $width,
            $request['height'],
            $state,
        ),
        '<title id="title">' . status_lights_escape($title) . '</title>',
        sprintf(
            '<rect width="%d" height="%d" rx="%d" fill="%s"/>',
            $width,
            $request['height'],
            $request['radius'],
            $background,
        ),
    ];

    if ($label !== '') {
        $svg[] = sprintf(
            '<text x="50%%" y="50%%" fill="%s" font-family="%s" font-size="%d" text-anchor="middle" dominant-baseline="central">%s</text>',
            $foreground,
            status_lights_escape($fonts[$request['font']]),
            $request['font_size'],
            status_lights_escape($label),
        );
    }

    $svg[] = '</svg>';

    return implode('', $svg);
}

function status_lights_render_error(string $message): string
{
    $safeMessage = status_lights_escape($message);

    return '<?xml version="1.0" encoding="UTF-8"?>'
        . '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="40" viewBox="0 0 240 40" role="img" aria-labelledby="title">'
        . '<title id="title">' . $safeMessage . '</title>'
        . '<rect width="240" height="40" rx="6" fill="#6e7781"/>'
        . '<text x="120" y="20" fill="#ffffff" font-family="Arial, Helvetica, sans-serif" font-size="14" text-anchor="middle" dominant-baseline="central">'
        . $safeMessage
        . '</text></svg>';
}

function status_lights_automatic_width(string $label, int $height, int $fontSize): int
{
    if ($label === '') {
        return $height;
    }

    $characters = preg_split('//u', $label, -1, PREG_SPLIT_NO_EMPTY);
    $characterCount = is_array($characters) ? count($characters) : strlen($label);
    $padding = (int) ceil($height * 0.28);

    return max($height, (int) ceil(($characterCount * $fontSize * 0.64) + ($padding * 2)));
}

function status_lights_contrast_color(string $hex): string
{
    $channels = [];

    foreach ([0, 2, 4] as $offset) {
        $channel = hexdec(substr($hex, $offset, 2)) / 255;
        $channels[] = $channel <= 0.04045
            ? $channel / 12.92
            : (($channel + 0.055) / 1.055) ** 2.4;
    }

    $luminance = (0.2126 * $channels[0]) + (0.7152 * $channels[1]) + (0.0722 * $channels[2]);
    $whiteContrast = 1.05 / ($luminance + 0.05);
    $blackContrast = ($luminance + 0.05) / 0.05;

    return $whiteContrast >= $blackContrast ? '#ffffff' : '#000000';
}

function status_lights_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * @param array{state: string, cache_status: string, fetched_at: int}|null $result
 */
function status_lights_send_svg(
    string $body,
    int $statusCode,
    int $cacheTtl,
    ?array $result = null,
): never {
    $etag = '"' . hash('sha256', $body) . '"';

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        header('ETag: ' . $etag);
        exit;
    }

    http_response_code($statusCode);
    header('Content-Type: image/svg+xml; charset=utf-8');
    header(sprintf('Cache-Control: public, max-age=%d, stale-if-error=300', $cacheTtl));
    header('Content-Security-Policy: default-src \'none\'; style-src \'unsafe-inline\'; sandbox');
    header('Cross-Origin-Resource-Policy: cross-origin');
    header('Access-Control-Allow-Origin: *');
    header('X-Content-Type-Options: nosniff');
    header('ETag: ' . $etag);

    if ($result !== null) {
        header('X-Status-Lights-State: ' . $result['state']);
        header('X-Status-Lights-Cache: ' . $result['cache_status']);
    }

    echo $body;
    exit;
}

/** @param array<string, mixed> $body */
function status_lights_send_json(array $body, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function status_lights_main(): never
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

    if ($path === '/') {
        header('Location: https://statuslights.dev/', true, 302);
        exit;
    }

    $config = status_lights_config();

    if ($path === '/health') {
        $cacheDirectory = (string) $config['cache_directory'];
        $cacheParent = dirname($cacheDirectory);
        $cacheWritable = is_dir($cacheDirectory)
            ? is_writable($cacheDirectory)
            : is_dir($cacheParent) && is_writable($cacheParent);
        $checks = [
            'curl' => extension_loaded('curl'),
            'cache_writable' => $cacheWritable,
        ];
        $healthy = !in_array(false, $checks, true);

        status_lights_send_json([
            'status' => $healthy ? 'ok' : 'degraded',
            'service' => 'status-lights-generator',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }

    try {
        $request = status_lights_parse_request($_SERVER['REQUEST_URI'] ?? '/');
        $result = status_lights_resolve_state($request, $config);

        status_lights_send_svg(
            status_lights_render_svg($request, $result),
            200,
            (int) $config['http_cache_ttl'],
            $result,
        );
    } catch (StatusLightsRouteException $exception) {
        status_lights_send_svg(
            status_lights_render_error($exception->getMessage()),
            $exception->statusCode,
            0,
        );
    } catch (Throwable) {
        status_lights_send_svg(
            status_lights_render_error('Status temporarily unavailable'),
            500,
            0,
        );
    }
}

if (!defined('STATUS_LIGHTS_TESTING')) {
    status_lights_main();
}
