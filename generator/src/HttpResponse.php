<?php

declare(strict_types=1);

namespace StatusLights;

final class HttpResponse
{
    public static function svg(
        string $body,
        int $statusCode,
        int $cacheTtl,
        ?StatusResult $result = null,
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
            header('X-Status-Lights-State: ' . $result->state);
            header('X-Status-Lights-Cache: ' . $result->cacheStatus);
        }

        echo $body;
        exit;
    }

    /** @param array<string, mixed> $body */
    public static function json(array $body, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('Access-Control-Allow-Origin: *');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}

