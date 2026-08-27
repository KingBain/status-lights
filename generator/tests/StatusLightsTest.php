<?php
declare(strict_types=1);

if (!defined('STATUS_LIGHTS_TESTING')) {
    define('STATUS_LIGHTS_TESTING', true);
}
if (!defined('STATUS_LIGHTS_APP_TESTING')) {
    define('STATUS_LIGHTS_APP_TESTING', true);
}

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../app.php';

class MockSystem implements StatusLightsSystem {
    public array $env = [];
    public string $input = '';
    public int $time = 1000;
    public array $files = []; // [path => content]
    public array $dirs = [];
    public array $perms = [];
    
    public function getenv(string $name): string { return $this->env[$name] ?? ''; }
    public function time(): int { return $this->time; }
    public function readInput(int $maxBytes): string { return substr($this->input, 0, $maxBytes); }
    public function isDir(string $path): bool { return in_array($path, $this->dirs, true); }
    public function isFile(string $path): bool { return array_key_exists($path, $this->files); }
    public function isWritable(string $path): bool { return true; }
    public function mkdir(string $path, int $permissions, bool $recursive): bool { $this->dirs[] = $path; return true; }
    public function fileGetContents(string $path): string|false { return $this->files[$path] ?? false; }
    public function filePutContents(string $path, string $data, int $flags = 0): int|false { $this->files[$path] = $data; return strlen($data); }
    public function rename(string $from, string $to): bool { $this->files[$to] = $this->files[$from]; unset($this->files[$from]); return true; }
    public function unlink(string $path): bool { unset($this->files[$path]); return true; }
    public function chmod(string $path, int $permissions): bool { $this->perms[$path] = $permissions; return true; }
    public function tempnam(string $dir, string $prefix): string|false { return $dir . '/' . $prefix . uniqid(); }
    public function filemtime(string $path): int|false { return $this->time; }
    public function createAtomicFile(string $path, string $contents): ?bool {
        if (isset($this->files[$path])) return false;
        $this->files[$path] = $contents;
        return true;
    }
    public function getJsonFilesInDirectory(string $path): iterable {
        foreach ($this->files as $filePath => $content) {
            if (str_starts_with($filePath, $path . '/') && str_ends_with($filePath, '.json')) yield $filePath => basename($filePath, '.json');
        }
    }
    public function fetchHttpJson(string $url, array $headers, int $timeout): array { return ['workflow_runs' => [['status' => 'completed', 'conclusion' => 'success']]]; }
    public function extensionLoaded(string $name): bool { return true; }
}

final class StatusLightsTest extends TestCase {
    private MockSystem $mock;

    protected function setUp(): void {
        $this->mock = new MockSystem();
        $this->mock->env['STATUS_LIGHTS_APP_STORE_DIR'] = '/data';
        $this->mock->env['STATUS_LIGHTS_GITHUB_WEBHOOK_SECRET'] = 'secret';
        $this->mock->dirs[] = '/data';
    }

    public function testHealthEndpointReturnsOk(): void {
        $response = status_lights_app_handle_request($this->mock, ['REQUEST_URI' => '/health']);
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('"status":"ok"', $response->body);
    }
    
    public function testResolvesWorkflowSuccessfully(): void {
        status_lights_app_write($this->mock, StatusLightsAppStoreKind::Repositories, status_lights_app_repo_key('owner', 'repo'), ['installed' => true]);
        status_lights_app_write($this->mock, StatusLightsAppStoreKind::Statuses, status_lights_app_status_key('owner', 'repo', 'ci.yml'), ['state' => 'success', 'updated_at' => 1000]);
        
        $response = status_lights_app_handle_request($this->mock, ['REQUEST_URI' => '/github/owner/repo/ci.yml.svg']);
        $this->assertSame(200, $response->statusCode);
        $this->assertStringContainsString('data-state="success"', $response->body);
    }
    
    public function testRejectsOversizedWebhook(): void {
        $this->mock->input = str_repeat('a', 1048577);
        $response = status_lights_app_handle_request($this->mock, ['REQUEST_URI' => '/webhooks/github', 'REQUEST_METHOD' => 'POST', 'CONTENT_LENGTH' => '1048577']);
        $this->assertSame(413, $response->statusCode);
    }
    
    public function testHandlesValidWorkflowRunWebhook(): void {
        $payload = json_encode(['repository' => ['owner' => ['login' => 'owner'], 'name' => 'repo', 'default_branch' => 'main'], 'workflow_run' => ['id' => 1, 'path' => 'ci.yml', 'head_branch' => 'main', 'status' => 'completed', 'conclusion' => 'success'], 'installation' => ['id' => 123]]);
        $this->mock->input = $payload;
        $signature = 'sha256=' . hash_hmac('sha256', $payload, 'secret');
        
        $response = status_lights_app_handle_request($this->mock, [
            'REQUEST_URI' => '/webhooks/github',
            'REQUEST_METHOD' => 'POST',
            'HTTP_X_GITHUB_EVENT' => 'workflow_run',
            'HTTP_X_GITHUB_DELIVERY' => '72d3162e-cc78-11e3-81ab-4c9367dc0958',
            'HTTP_X_HUB_SIGNATURE_256' => $signature
        ]);
        
        $this->assertSame(202, $response->statusCode);
        $statusKey = status_lights_app_status_key('owner', 'repo', 'ci.yml');
        $this->assertArrayHasKey('/data/statuses/' . $statusKey . '.json', $this->mock->files);
    }
    
    public function testStatusLightsRealSystemIntegration(): void {
        $real = new StatusLightsRealSystem('php://memory');
        $temp = $real->tempnam(sys_get_temp_dir(), 'real-test-');
        $this->assertTrue($real->createAtomicFile($temp, 'test'));
        $this->assertFalse($real->createAtomicFile($temp, 'fail')); // Exists
        $this->assertSame('test', $real->fileGetContents($temp));
        $this->assertTrue($real->unlink($temp));
    }
}