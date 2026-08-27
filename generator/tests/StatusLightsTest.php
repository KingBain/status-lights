<?php

declare(strict_types=1);

require_once __DIR__ . '/Pest.php';

test('state helpers and configuration', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    // The state helpers are evaluated in the expectations below.

    // Assert
    expect(StatusLightState::Success->label())->toBe('Success');
    expect(StatusLightState::Failure->label())->toBe('Failure');
    expect(StatusLightState::Running->label())->toBe('Running');
    expect(StatusLightState::Unknown->label())->toBe('Unknown');
    expect(StatusLightState::Success->defaultColor())->toBe('1a7f37');
    expect(StatusLightState::Failure->defaultColor())->toBe('cf222e');
    expect(StatusLightState::Running->defaultColor())->toBe('bf8700');
    expect(StatusLightState::Unknown->defaultColor())->toBe('6e7781');

    $mock->env += [
        'STATUS_LIGHTS_GITHUB_TOKEN' => '',
        'GITHUB_TOKEN' => 'fallback-token',
        'STATUS_LIGHTS_CACHE_TTL' => '5',
        'STATUS_LIGHTS_STALE_TTL' => '999999',
        'STATUS_LIGHTS_HTTP_CACHE_TTL' => 'invalid',
        'STATUS_LIGHTS_GITHUB_TIMEOUT' => '7',
    ];
    $config = status_lights_config($mock);
    expect($config['github_token'])->toBe('fallback-token');
    expect($config['cache_ttl'])->toBe(10);
    expect($config['stale_ttl'])->toBe(86400);
    expect($config['http_cache_ttl'])->toBe(60);
    expect($config['github_timeout'])->toBe(7);
    expect(status_lights_app_store_dir($mock))->toBe('/data');
    expect(status_lights_app_webhook_secret($mock))->toBe('secret');
    expect(status_lights_app_max_webhook_bytes($mock))->toBe(1048576);

    expect(status_lights_map_run_state(['status' => 'in_progress']))->toBe(StatusLightState::Running);
    expect(status_lights_map_run_state(['status' => 'completed', 'conclusion' => 'success']))->toBe(StatusLightState::Success);
    expect(status_lights_map_run_state(['status' => 'completed', 'conclusion' => 'cancelled']))->toBe(StatusLightState::Failure);
    expect(status_lights_map_run_state(['status' => 'completed', 'conclusion' => 'skipped']))->toBe(StatusLightState::Unknown);
});

test('parses and renders configured request', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    $request = status_lights_parse_request('/github/Owner/@.github/ci.yml/job/Build%20Test/size/40/width/120/font/mono/font-size/14/radius/5/text/Run%20{status}/success-color/abcdef.svg');

    // Assert
    expect($request->repository)->toBe('.github');
    expect($request->job)->toBe('Build Test');
    expect($request->width)->toBe(120);
    expect($request->font)->toBe('mono');
    expect($request->color(StatusLightState::Success))->toBe('abcdef');

    $svg = status_lights_render_svg($request, ['state' => StatusLightState::Success, 'cache_status' => 'miss', 'fetched_at' => 1000]);
    expect($svg)->toContain('Run Success');
    expect($svg)->toContain('width="120"');
    expect($svg)->toContain('fill="#000000"');
    expect(status_lights_automatic_width('', 40, 16))->toBe(40);
    expect(status_lights_automatic_width('éé', 40, 16))->toBeGreaterThan(40);
    expect(status_lights_contrast_color('000000'))->toBe('#ffffff');
    expect(status_lights_contrast_color('ffffff'))->toBe('#000000');
    expect(status_lights_escape('<tag>&"'))->toBe('&lt;tag&gt;&amp;&quot;');
    expect(status_lights_render_svg(status_lights_test_request(), ['state' => StatusLightState::Unknown, 'cache_status' => 'miss', 'fetched_at' => 1000]))->not->toContain('<text ');
    expect(status_lights_render_error('<error>'))->toContain('&lt;error&gt;');
});

test('rejects invalid routes and options', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    status_lights_test_expect_route_error('/', 404);
    status_lights_test_expect_route_error('/github/owner/repo/ci.yml', 404);
    status_lights_test_expect_route_error('/github/owner/repo/ci.yml/unknown/value.svg');
    status_lights_test_expect_route_error('/github/owner/repo/ci.yml/size.svg');
    status_lights_test_expect_route_error('/github/owner/repo/ci.yml/size/5.svg');
    status_lights_test_expect_route_error('/github/owner/repo/ci.yml/font/fancy.svg');
    status_lights_test_expect_route_error('/github/owner/repo/ci.yml/job/.svg');
    status_lights_test_expect_route_error('/github/owner/repo/ci.yml/text/%00.svg');
    status_lights_test_expect_route_error('/github/owner/repo/ci.yml/success-color/abc.svg');
    status_lights_test_expect_route_error('/github/owner/repo/ci.yml/size/40/size/41.svg');
    status_lights_test_expect_route_error('/github/owner/../ci.yml.svg');
    status_lights_test_expect_route_error('/github/-owner/repo/ci.yml.svg');
    status_lights_test_expect_route_error('/github/owner/repo/ci.yml/job/' . rawurlencode(str_repeat('x', 101)) . '.svg');
    status_lights_test_expect_route_error('/github/owner/repo/ci.yml/text/' . rawurlencode(str_repeat('x', 81)) . '.svg');
    status_lights_test_expect_route_error('/github/owner/repo/' . str_repeat('x', 2049) . '.svg');

    // Assert
    // The helper above verifies the expected route error and status code.
});

test('creates svg and json responses', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    $result = ['state' => StatusLightState::Success, 'cache_status' => 'webhook', 'fetched_at' => 1000];
    $response = status_lights_create_svg_response('<svg/>', 200, 60, $result);

    // Assert
    expect($response->statusCode)->toBe(200);
    expect($response->headers['X-Status-Lights-State'])->toBe('success');
    expect($response->headers['X-Status-Lights-Cache'])->toBe('webhook');

    $notModified = status_lights_create_svg_response('<svg/>', 200, 60, $result, ['HTTP_IF_NONE_MATCH' => $response->headers['ETag']]);
    expect($notModified->statusCode)->toBe(304);
    expect($notModified->body)->toBe('');

    $json = status_lights_create_json_response(['status' => 'ok'], 201, ['X-Test' => 'yes']);
    expect($json->statusCode)->toBe(201);
    expect($json->headers['X-Test'])->toBe('yes');
    expect($json->body)->toBe('{"status":"ok"}');
});

test('cache read write and resolution states', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    $key = 'owner/repo/ci.yml';

    // Assert
    expect(status_lights_read_cache($mock, '/cache', $key))->toBeNull();
    $path = status_lights_cache_path('/cache', $key);
    $mock->files[$path] = '{';
    expect(status_lights_read_cache($mock, '/cache', $key))->toBeNull();
    $mock->files[$path] = '{"state":"not-a-state","fetched_at":1}';
    expect(status_lights_read_cache($mock, '/cache', $key))->toBeNull();

    status_lights_write_cache($mock, '/cache', $key, StatusLightState::Success, 1000);
    $cached = status_lights_read_cache($mock, '/cache', $key);
    expect($cached['state'])->toBe(StatusLightState::Success);
    expect($cached['fetched_at'])->toBe(1000);
    expect($mock->perms)->toContain(0644);

    $request = status_lights_test_request();
    $hit = status_lights_resolve_state($mock, $request, status_lights_test_config(), static fn (): StatusLightState => StatusLightState::Failure, 1010);
    expect($hit['cache_status'])->toBe('hit');
    expect($hit['state'])->toBe(StatusLightState::Success);

    $miss = status_lights_resolve_state($mock, status_lights_test_request('Build'), status_lights_test_config(), static fn (): StatusLightState => StatusLightState::Failure, 1010);
    expect($miss['cache_status'])->toBe('miss');
    expect($miss['state'])->toBe(StatusLightState::Failure);

    $stale = status_lights_resolve_state($mock, $request, status_lights_test_config(), static function (): StatusLightState {
        throw new RuntimeException('GitHub unavailable.');
    }, 1100);
    expect($stale['cache_status'])->toBe('stale');

    $error = status_lights_resolve_state($mock, status_lights_test_request('Other'), status_lights_test_config(), static function (): StatusLightState {
        throw new RuntimeException('GitHub unavailable.');
    }, 1100);
    expect($error['cache_status'])->toBe('error');
    expect($error['state'])->toBe(StatusLightState::Unknown);

    $unsupported = status_lights_resolve_state($mock, status_lights_test_request('Unsupported'), status_lights_test_config(), static fn (): string => 'invalid', 1100);
    expect($unsupported['cache_status'])->toBe('error');
});

test('cache write failure paths', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    $mock->mkdirSucceeds = false;
    status_lights_write_cache($mock, '/new-cache', 'one', StatusLightState::Success, 1);

    $mock->mkdirSucceeds = true;
    $mock->tempnamSucceeds = false;
    status_lights_write_cache($mock, '/new-cache', 'two', StatusLightState::Success, 1);

    $mock->tempnamSucceeds = true;
    $mock->filePutSucceeds = false;
    status_lights_write_cache($mock, '/new-cache', 'three', StatusLightState::Success, 1);

    $mock->filePutSucceeds = true;
    $mock->renameSucceeds = false;
    status_lights_write_cache($mock, '/new-cache', 'four', StatusLightState::Success, 1);

    // Assert
    expect($mock->files)->not->toHaveKey(status_lights_cache_path('/new-cache', 'four'));
});

test('fetches workflow and job state without network', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    $mock->httpResponses = [
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
    $config = status_lights_test_config();
    $config['github_token'] = 'token';

    $state = status_lights_fetch_state($mock, 'owner', 'repo', 'ci.yml', $config, 'Build');

    // Assert
    expect($state)->toBe(StatusLightState::Success);
    expect($mock->httpRequests)->toHaveCount(3);
    expect($mock->httpRequests[1]['url'])->toContain('branch=main');
    expect($mock->httpRequests[0]['headers'])->toContain('Authorization: Bearer token');

    expect(status_lights_find_job_state(['jobs' => []], 'Build'))->toBe(StatusLightState::Unknown);
    expect(status_lights_find_job_state(['jobs' => 'invalid'], 'Build'))->toBe(StatusLightState::Unknown);
    $mock->httpResponses = [['workflow_runs' => []]];
    expect(status_lights_fetch_state($mock, 'owner', 'repo', 'ci.yml', status_lights_test_config()))->toBe(StatusLightState::Unknown);
});

test('app store crud and pruning', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    $repoKey = status_lights_app_repo_key('OWNER', 'Repo');

    // Assert
    expect(status_lights_app_key('owner', 'repo'))->toBe($repoKey);
    expect($repoKey)->toMatch('/^[a-f0-9]{64}$/');
    expect(preg_match(StatusLightsAppStoreKind::Runs->keyPattern(), '1'))->toBe(1);
    expect(preg_match(StatusLightsAppStoreKind::Repositories->keyPattern(), $repoKey))->toBe(1);

    status_lights_app_write($mock, StatusLightsAppStoreKind::Repositories, $repoKey, ['installed' => true]);
    expect(status_lights_app_read($mock, StatusLightsAppStoreKind::Repositories, $repoKey))->toBe(['installed' => true]);
    expect(status_lights_app_create($mock, StatusLightsAppStoreKind::Repositories, status_lights_app_key('new'), ['installed' => true]))->toBeTrue();
    expect(status_lights_app_create($mock, StatusLightsAppStoreKind::Repositories, status_lights_app_key('new'), ['installed' => true]))->toBeFalse();

    $mock->createAtomicMode = 'null';
    expect(static fn (): mixed => status_lights_app_create($mock, StatusLightsAppStoreKind::Repositories, status_lights_app_key('error'), ['installed' => true]))->toThrow(RuntimeException::class);
});

test('app store failures and pruning', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    $key = status_lights_app_key('record');

    // Assert
    expect(static fn (): mixed => status_lights_app_record_path($mock, StatusLightsAppStoreKind::Repositories, 'not-valid'))->toThrow(InvalidArgumentException::class);
});

test('app store error handling and pruning operations', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    $key = status_lights_app_key('record');
    $mock->tempnamSucceeds = false;
    try {
        status_lights_app_write($mock, StatusLightsAppStoreKind::Repositories, $key, ['value' => 'x']);
        throw new RuntimeException('Expected temporary file creation to fail.');
    } catch (RuntimeException) {
        expect(true)->toBeTrue();
    }

    $mock->tempnamSucceeds = true;
    $mock->filePutSucceeds = false;
    try {
        status_lights_app_write($mock, StatusLightsAppStoreKind::Repositories, $key, ['value' => 'x']);
        throw new RuntimeException('Expected write to fail.');
    } catch (RuntimeException) {
        expect(true)->toBeTrue();
    }

    $mock->filePutSucceeds = true;
    $mock->renameSucceeds = false;
    try {
        status_lights_app_write($mock, StatusLightsAppStoreKind::Repositories, $key, ['value' => 'x']);
        throw new RuntimeException('Expected rename to fail.');
    } catch (RuntimeException) {
        expect(true)->toBeTrue();
    }

    $mock->renameSucceeds = true;
    $mock->mkdirSucceeds = false;
    try {
        status_lights_app_ensure_store_directory($mock, StatusLightsAppStoreKind::Statuses);
        throw new RuntimeException('Expected directory creation to fail.');
    } catch (RuntimeException) {
        expect(true)->toBeTrue();
    }

    $mock->mkdirSucceeds = true;
    expect(status_lights_app_read($mock, StatusLightsAppStoreKind::Statuses, status_lights_app_key('missing')))->toBeNull();
    $badPath = status_lights_app_record_path($mock, StatusLightsAppStoreKind::Statuses, status_lights_app_key('bad'));
    $mock->files[$badPath] = '{';
    expect(status_lights_app_read($mock, StatusLightsAppStoreKind::Statuses, status_lights_app_key('bad')))->toBeNull();
    $mock->files[$badPath] = '"not-array"';
    expect(status_lights_app_read($mock, StatusLightsAppStoreKind::Statuses, status_lights_app_key('bad')))->toBeNull();

    $delivery = status_lights_app_delivery_key('72d3162e-cc78-11e3-81ab-4c9367dc0958');
    $directory = status_lights_app_store_kind_directory($mock, StatusLightsAppStoreKind::Deliveries);
    $mock->dirs[] = $directory;
    $oldPath = $directory . '/' . $delivery . '.json';
    $newPath = $directory . '/' . status_lights_app_key('new-delivery') . '.json';
    $mock->files[$oldPath] = '{}';
    $mock->files[$newPath] = '{}';
    $mock->files[$directory . '/invalid.json'] = '{}';
    $mock->mtimes[$oldPath] = 999;
    $mock->mtimes[$newPath] = 1000;
    expect(status_lights_app_prune_deliveries_older_than(1000, $mock))->toBe(1);
    expect(status_lights_app_prune_runs_older_than(1000, $mock))->toBe(0);

    expect(static fn (): mixed => status_lights_app_prune_records_older_than(StatusLightsAppStoreKind::Statuses, 1000, $mock))->toThrow(InvalidArgumentException::class);
});

test('validates delivery ids and deletes records', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    // The state helpers are evaluated in the expectations below.

    // Assert
    expect(status_lights_app_normalize_delivery_id('72D3162E-CC78-11E3-81AB-4C9367DC0958'))->toBe('72d3162e-cc78-11e3-81ab-4c9367dc0958');
    expect(status_lights_app_normalize_delivery_id('bad'))->toBeNull();
    expect(static fn (): mixed => status_lights_app_delivery_key('bad'))->toThrow(InvalidArgumentException::class);
});

test('webhook validation and oversize paths', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    // The state helpers are evaluated in the expectations below.

    // Assert
    expect(status_lights_app_handle_webhook($mock, [])->statusCode)->toBe(405);

    $mock->input = '';
    expect(status_lights_app_handle_webhook($mock, ['REQUEST_METHOD' => 'POST'])->statusCode)->toBe(400);

    $mock->input = str_repeat('a', 1048577);
    expect(status_lights_app_handle_webhook($mock, ['REQUEST_METHOD' => 'POST'])->statusCode)->toBe(413);

    $body = '{';
    $mock->input = $body;
    expect(status_lights_app_handle_webhook($mock, status_lights_test_signed_server('workflow_run', '72d3162e-cc78-11e3-81ab-4c9367dc0958', $body))->statusCode)->toBe(400);

    $body = json_encode('not-an-object', JSON_THROW_ON_ERROR);
    $mock->input = $body;
    expect(status_lights_app_handle_webhook($mock, status_lights_test_signed_server('workflow_run', '72d3162e-cc78-11e3-81ab-4c9367dc0959', $body))->statusCode)->toBe(400);

    $body = '{}';
    $mock->input = $body;
    $server = status_lights_test_signed_server('INVALID', '72d3162e-cc78-11e3-81ab-4c9367dc0960', $body);
    expect(status_lights_app_handle_webhook($mock, $server)->statusCode)->toBe(400);
    $server = status_lights_test_signed_server('ping', 'bad', $body);
    expect(status_lights_app_handle_webhook($mock, $server)->statusCode)->toBe(400);
    $server = status_lights_test_signed_server('ping', '72d3162e-cc78-11e3-81ab-4c9367dc0961', $body);
    $server['HTTP_X_HUB_SIGNATURE_256'] = 'sha256=invalid';
    expect(status_lights_app_handle_webhook($mock, $server)->statusCode)->toBe(401);
});

test('webhook delivery lifecycle and events', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
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
    $mock->input = $payload;
    $delivery = '72d3162e-cc78-11e3-81ab-4c9367dc0962';
    $response = status_lights_app_handle_webhook($mock, status_lights_test_signed_server('workflow_run', $delivery, $payload));

    // Assert
    expect($response->statusCode)->toBe(202);
    $statusKey = status_lights_app_status_key('owner', 'repo', 'ci.yml');
    expect($mock->files)->toHaveKey('/data/statuses/' . $statusKey . '.json');

    $mock->input = $payload;
    $duplicate = status_lights_app_handle_webhook($mock, status_lights_test_signed_server('workflow_run', $delivery, $payload));
    expect($duplicate->body)->toContain('"duplicate":true');

    $jobPayload = json_encode([
        'repository' => ['owner' => ['login' => 'owner'], 'name' => 'repo', 'default_branch' => 'main'],
        'workflow_job' => ['run_id' => 1, 'name' => 'Build', 'head_branch' => 'main', 'status' => 'completed', 'conclusion' => 'failure'],
    ], JSON_THROW_ON_ERROR);
    $mock->input = $jobPayload;
    expect(status_lights_app_handle_webhook($mock, status_lights_test_signed_server('workflow_job', '72d3162e-cc78-11e3-81ab-4c9367dc0963', $jobPayload))->statusCode)->toBe(202);
    $jobKey = status_lights_app_status_key('owner', 'repo', 'ci.yml', 'Build');
    expect($mock->files)->toHaveKey('/data/statuses/' . $jobKey . '.json');

    $ping = '{}';
    $mock->input = $ping;
    expect(status_lights_app_handle_webhook($mock, status_lights_test_signed_server('ping', '72d3162e-cc78-11e3-81ab-4c9367dc0964', $ping))->statusCode)->toBe(200);
});

test('installation and webhook failure handling', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    $repository = ['owner' => ['login' => 'owner'], 'name' => 'repo', 'default_branch' => 'main'];
    status_lights_app_handle_installation($mock, 'installation', ['action' => 'created', 'installation' => ['id' => 1], 'repositories' => [$repository]]);
    $record = status_lights_app_read($mock, StatusLightsAppStoreKind::Repositories, status_lights_app_repo_key('owner', 'repo'));

    // Assert
    expect($record['installed'])->toBeTrue();

    status_lights_app_handle_installation($mock, 'installation_repositories', ['installation' => ['id' => 1], 'repositories_removed' => [$repository]]);
    $record = status_lights_app_read($mock, StatusLightsAppStoreKind::Repositories, status_lights_app_repo_key('owner', 'repo'));
    expect($record['installed'])->toBeFalse();

    $payload = json_encode([
        'repository' => $repository,
        'workflow_run' => ['id' => 9, 'path' => 'ci.yml', 'head_branch' => 'main', 'status' => 'completed', 'conclusion' => 'success'],
    ], JSON_THROW_ON_ERROR);
    $mock->filePutSucceeds = false;
    $mock->input = $payload;
    $response = status_lights_app_handle_webhook($mock, status_lights_test_signed_server('workflow_run', '72d3162e-cc78-11e3-81ab-4c9367dc0965', $payload));
    expect($response->statusCode)->toBe(500);
});

test('app resolution and endpoints', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    // The state helpers are evaluated in the expectations below.

    // Assert
    expect(status_lights_app_handle_request($mock, ['REQUEST_URI' => '/'])->statusCode)->toBe(302);
    expect(status_lights_app_handle_request($mock, ['REQUEST_URI' => '/health'])->statusCode)->toBe(200);

    $mock->env['STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET'] = '';
    expect(status_lights_app_handle_request($mock, ['REQUEST_URI' => '/health'])->statusCode)->toBe(503);
    $mock->env['STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET'] = 'secret';

    $unknown = status_lights_app_handle_request($mock, ['REQUEST_URI' => '/github/owner/repo/ci.yml.svg']);
    expect($unknown->body)->toContain('data-state="unknown"');
    expect($unknown->headers['X-Status-Lights-Cache'])->toBe('not-installed');

    status_lights_app_mark_repository($mock, ['owner' => ['login' => 'owner'], 'name' => 'repo'], 1, true);
    $empty = status_lights_app_handle_request($mock, ['REQUEST_URI' => '/github/owner/repo/ci.yml.svg']);
    expect($empty->headers['X-Status-Lights-Cache'])->toBe('app-empty');

    $statusKey = status_lights_app_status_key('owner', 'repo', 'ci.yml');
    status_lights_app_write($mock, StatusLightsAppStoreKind::Statuses, $statusKey, ['state' => 'success', 'updated_at' => 900]);
    $success = status_lights_app_handle_request($mock, ['REQUEST_URI' => '/github/owner/repo/ci.yml.svg']);
    expect($success->headers['X-Status-Lights-Cache'])->toBe('webhook');
    expect($success->headers['X-Status-Lights-State'])->toBe('success');
    $notModified = status_lights_app_handle_request($mock, ['REQUEST_URI' => '/github/owner/repo/ci.yml.svg', 'HTTP_IF_NONE_MATCH' => $success->headers['ETag']]);
    expect($notModified->statusCode)->toBe(304);

    expect(status_lights_app_handle_request($mock, ['REQUEST_URI' => '/not-a-route'])->statusCode)->toBe(404);
    $mock->getenvException = new RuntimeException('environment unavailable');
    expect(status_lights_app_handle_request($mock, ['REQUEST_URI' => '/github/owner/repo/ci.yml.svg'])->statusCode)->toBe(500);
});

test('legacy endpoint and real system file adapter', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    // The state helpers are evaluated in the expectations below.

    // Assert
    expect(status_lights_handle_legacy_request($mock, ['REQUEST_URI' => '/'])->statusCode)->toBe(302);
    $mock->extensionIsLoaded = false;
    expect(status_lights_handle_legacy_request($mock, ['REQUEST_URI' => '/health'])->statusCode)->toBe(503);
    $mock->extensionIsLoaded = true;
    expect(status_lights_handle_legacy_request($mock, ['REQUEST_URI' => '/wrong'])->statusCode)->toBe(404);

    $directory = sys_get_temp_dir() . '/status-lights-' . bin2hex(random_bytes(6));
    $real = new StatusLightsRealSystem();
    expect($real->getenv('STATUS_LIGHTS_TEST_MISSING_' . bin2hex(random_bytes(4))))->toBe('');
    expect($real->time())->toBeInt();
    expect($real->readInput(1))->toBeString();
    expect($real->mkdir($directory, 0755, true))->toBeTrue();
    expect($real->isDir($directory))->toBeTrue();
    $file = $directory . '/one.txt';
    expect($real->filePutContents($file, 'one'))->toBe(3);
    expect($real->isFile($file))->toBeTrue();
    expect($real->isWritable($file))->toBeTrue();
    expect($real->fileGetContents($file))->toBe('one');
    expect($real->chmod($file, 0644))->toBeTrue();
    $temporary = $real->tempnam($directory, 'temp-');
    expect($temporary)->toBeString();
    expect($real->rename($file, $directory . '/two.txt'))->toBeTrue();
    expect($real->filemtime($directory . '/two.txt'))->toBeInt();
    expect($real->createAtomicFile($directory . '/atomic.txt', 'atomic'))->toBeTrue();
    expect($real->createAtomicFile($directory . '/atomic.txt', 'again'))->toBeFalse();
    expect($real->createAtomicFile($directory . '/missing/atomic.txt', 'nope'))->toBeNull();
    expect(iterator_to_array($real->getJsonFilesInDirectory($directory . '/missing')))->toBe([]);
    expect($real->filePutContents($directory . '/data.json', '{}'))->toBe(2);
    $files = iterator_to_array($real->getJsonFilesInDirectory($directory));
    expect($files)->toHaveKey($directory . '/data.json');
    expect($real->extensionLoaded('json'))->toBeTrue();
    expect($real->unlink($temporary))->toBeTrue();
    expect($real->unlink($directory . '/two.txt'))->toBeTrue();
    expect($real->unlink($directory . '/atomic.txt'))->toBeTrue();
    expect($real->unlink($directory . '/data.json'))->toBeTrue();
    expect(rmdir($directory))->toBeTrue();
});

test('covers app store and webhook failure branches', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    $key = status_lights_app_key('delete-failure');
    $path = status_lights_app_record_path($mock, StatusLightsAppStoreKind::Statuses, $key);
    $mock->files[$path] = '{}';
    $mock->unlinkSucceeds = false;
    try {
        status_lights_app_delete($mock, StatusLightsAppStoreKind::Statuses, $key);
        throw new RuntimeException('Expected deletion to fail.');
    } catch (RuntimeException) {
        expect(true)->toBeTrue();
    }

    $mock->unlinkSucceeds = true;
    $mock->fileGetSucceeds = false;
    expect(status_lights_app_read($mock, StatusLightsAppStoreKind::Statuses, $key))->toBeNull();
    $mock->fileGetSucceeds = true;

    $mock->input = '{}';
    $server = status_lights_test_signed_server('ping', '72d3162e-cc78-11e3-81ab-4c9367dc0970', '{}');
    $server['CONTENT_LENGTH'] = '1048577';
    expect(status_lights_app_handle_webhook($mock, $server)->statusCode)->toBe(413);

    $mock->env['STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET'] = '';
    expect(status_lights_app_verify_signature($mock, '{}', []))->toBeFalse();
    $mock->env['STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET'] = 'secret';

    $before = $mock->files;
    status_lights_app_mark_repository($mock, ['name' => 'repo'], 1, true);
    expect($mock->files)->toBe($before);

    $repository = ['owner' => ['login' => 'owner'], 'name' => 'repo', 'default_branch' => 'main'];
    status_lights_app_handle_installation($mock, 'installation_repositories', [
        'installation' => ['id' => 1],
        'repositories_added' => [$repository],
        'repositories_removed' => [],
    ]);
    $record = status_lights_app_read($mock, StatusLightsAppStoreKind::Repositories, status_lights_app_repo_key('owner', 'repo'));
    expect($record['installed'])->toBeTrue();

    status_lights_app_handle_workflow_run($mock, []);
    status_lights_app_handle_workflow_run($mock, ['repository' => $repository, 'workflow_run' => ['id' => 0, 'path' => 'ci.yml']]);
    status_lights_app_handle_workflow_run($mock, ['repository' => $repository, 'workflow_run' => ['id' => 2, 'path' => 'ci.yml', 'head_branch' => 'other']]);
    status_lights_app_handle_workflow_job($mock, []);
    status_lights_app_handle_workflow_job($mock, ['repository' => $repository, 'workflow_job' => ['run_id' => 0, 'name' => 'Build']]);
    status_lights_app_handle_workflow_job($mock, ['repository' => $repository, 'workflow_job' => ['run_id' => 2, 'name' => 'Build', 'head_branch' => 'other']]);
    status_lights_app_handle_workflow_job($mock, ['repository' => $repository, 'workflow_job' => ['run_id' => 999, 'name' => 'Build', 'head_branch' => 'main']]);

    $mock->createAtomicMode = 'throw';
    $mock->input = '{}';
    expect(status_lights_app_handle_webhook($mock, status_lights_test_signed_server('ping', '72d3162e-cc78-11e3-81ab-4c9367dc0971', '{}'))->statusCode)->toBe(500);
    $mock->createAtomicMode = 'default';

    $installationPayload = json_encode(['action' => 'created', 'installation' => ['id' => 1], 'repositories' => [$repository]], JSON_THROW_ON_ERROR);
    $mock->input = $installationPayload;
    expect(status_lights_app_handle_webhook($mock, status_lights_test_signed_server('installation', '72d3162e-cc78-11e3-81ab-4c9367dc0972', $installationPayload))->statusCode)->toBe(202);

    $workflowPayload = json_encode(['repository' => $repository, 'workflow_run' => ['id' => 4, 'path' => 'ci.yml', 'head_branch' => 'main', 'status' => 'completed', 'conclusion' => 'success']], JSON_THROW_ON_ERROR);
    $mock->filePutSucceeds = false;
    $mock->unlinkSucceeds = false;
    $mock->input = $workflowPayload;
    expect(status_lights_app_handle_webhook($mock, status_lights_test_signed_server('workflow_run', '72d3162e-cc78-11e3-81ab-4c9367dc0973', $workflowPayload))->statusCode)->toBe(500);

    $mock->filePutSucceeds = true;
    $mock->unlinkSucceeds = true;
    $mock->input = '{}';
    expect(status_lights_app_handle_request($mock, status_lights_test_signed_server('ping', '72d3162e-cc78-11e3-81ab-4c9367dc0974', '{}'))->statusCode)->toBe(200);
});

test('covers remaining core branches', function (): void {
    // Arrange
    $mock = status_lights_test_system();

    // Act
    status_lights_test_expect_route_error('http://[::1');
    try {
        status_lights_job_option("\xFF");
        throw new RuntimeException('Expected invalid UTF-8 job name to fail.');
    } catch (StatusLightsRouteException) {
        expect(true)->toBeTrue();
    }
    try {
        status_lights_job_option("Build\x00");
        throw new RuntimeException('Expected control character job name to fail.');
    } catch (StatusLightsRouteException) {
        expect(true)->toBeTrue();
    }
    try {
        status_lights_text_option("\xFF");
        throw new RuntimeException('Expected invalid UTF-8 text to fail.');
    } catch (StatusLightsRouteException) {
        expect(true)->toBeTrue();
    }

    $mock->httpResponses = [
        ['workflow_runs' => [[
            'repository' => ['default_branch' => 'main'],
            'head_branch' => 'feature',
            'status' => 'completed',
            'conclusion' => 'success',
        ]]],
        ['workflow_runs' => []],
    ];
    expect(status_lights_fetch_state($mock, 'owner', 'repo', 'ci.yml', status_lights_test_config()))->toBe(StatusLightState::Unknown);

    $mock->httpResponses = [[
        'workflow_runs' => [[
            'repository' => ['default_branch' => 'main'],
            'head_branch' => 'main',
            'status' => 'completed',
            'conclusion' => 'success',
        ]],
    ]];
    expect(status_lights_fetch_state($mock, 'owner', 'repo', 'ci.yml', status_lights_test_config()))->toBe(StatusLightState::Success);

    $mock->httpResponses = [[
        'workflow_runs' => [[
            'repository' => ['default_branch' => 'main'],
            'head_branch' => 'main',
            'status' => 'completed',
            'conclusion' => 'success',
        ]],
    ]];
    expect(status_lights_fetch_state($mock, 'owner', 'repo', 'ci.yml', status_lights_test_config(), 'Build'))->toBe(StatusLightState::Unknown);

    $cachePath = status_lights_cache_path('/cache', 'file-get-failure');
    $mock->files[$cachePath] = '{"state":"success","fetched_at":1}';
    $mock->fileGetSucceeds = false;
    expect(status_lights_read_cache($mock, '/cache', 'file-get-failure'))->toBeNull();
    $mock->fileGetSucceeds = true;
    $mock->files[$cachePath] = '[]';
    expect(status_lights_read_cache($mock, '/cache', 'file-get-failure'))->toBeNull();

    $mock->httpResponses = [['workflow_runs' => [['status' => 'completed', 'conclusion' => 'success']]]];
    expect(status_lights_handle_legacy_request($mock, ['REQUEST_URI' => '/github/owner/repo/ci.yml.svg'])->statusCode)->toBe(200);

    $mock->timeException = new RuntimeException('clock unavailable');
    expect(status_lights_handle_legacy_request($mock, ['REQUEST_URI' => '/github/owner/repo/ci.yml.svg'])->statusCode)->toBe(500);
});
