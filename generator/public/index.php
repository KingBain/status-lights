<?php

declare(strict_types=1);

use StatusLights\Config;
use StatusLights\FileCache;
use StatusLights\GitHubClient;
use StatusLights\HttpResponse;
use StatusLights\InvalidRoute;
use StatusLights\RouteParser;
use StatusLights\StatusResolver;
use StatusLights\SvgRenderer;

require dirname(__DIR__) . '/src/bootstrap.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($path === '/') {
    header('Location: https://statuslights.dev/', true, 302);
    exit;
}

if ($path === '/health') {
    $config = Config::fromEnvironment();
    $cacheParent = dirname($config->cacheDirectory);
    $cacheWritable = is_dir($config->cacheDirectory)
        ? is_writable($config->cacheDirectory)
        : is_dir($cacheParent) && is_writable($cacheParent);
    $checks = [
        'curl' => extension_loaded('curl'),
        'cache_writable' => $cacheWritable,
    ];
    $healthy = !in_array(false, $checks, true);

    HttpResponse::json([
        'status' => $healthy ? 'ok' : 'degraded',
        'service' => 'status-lights-generator',
        'checks' => $checks,
    ], $healthy ? 200 : 503);
}

$config = Config::fromEnvironment();
$renderer = new SvgRenderer();

try {
    $request = (new RouteParser())->parse($_SERVER['REQUEST_URI'] ?? '/');
    $resolver = new StatusResolver(
        provider: new GitHubClient($config->githubTimeout, $config->githubToken),
        cache: new FileCache($config->cacheDirectory),
        cacheTtl: $config->cacheTtl,
        staleTtl: $config->staleTtl,
    );
    $result = $resolver->resolve($request);

    HttpResponse::svg(
        body: $renderer->render($request, $result),
        statusCode: 200,
        cacheTtl: $config->httpCacheTtl,
        result: $result,
    );
} catch (InvalidRoute $exception) {
    HttpResponse::svg(
        body: $renderer->renderError($exception->getMessage()),
        statusCode: $exception->statusCode,
        cacheTtl: 0,
    );
} catch (\Throwable) {
    HttpResponse::svg(
        body: $renderer->renderError('Status temporarily unavailable'),
        statusCode: 500,
        cacheTtl: 0,
    );
}
