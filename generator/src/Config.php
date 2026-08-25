<?php

declare(strict_types=1);

namespace StatusLights;

final readonly class Config
{
    public function __construct(
        public string $cacheDirectory,
        public int $cacheTtl,
        public int $staleTtl,
        public int $httpCacheTtl,
        public int $githubTimeout,
        public ?string $githubToken,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $token = self::environment('STATUS_LIGHTS_GITHUB_TOKEN');

        if ($token === '') {
            $token = self::environment('GITHUB_TOKEN');
        }

        return new self(
            cacheDirectory: self::environment('STATUS_LIGHTS_CACHE_DIR')
                ?: dirname(__DIR__) . '/var/cache',
            cacheTtl: self::integerEnvironment('STATUS_LIGHTS_CACHE_TTL', 60, 10, 3600),
            staleTtl: self::integerEnvironment('STATUS_LIGHTS_STALE_TTL', 3600, 60, 86400),
            httpCacheTtl: self::integerEnvironment('STATUS_LIGHTS_HTTP_CACHE_TTL', 60, 0, 3600),
            githubTimeout: self::integerEnvironment('STATUS_LIGHTS_GITHUB_TIMEOUT', 5, 1, 30),
            githubToken: $token !== '' ? $token : null,
        );
    }

    private static function environment(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }

    private static function integerEnvironment(
        string $name,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        $value = self::environment($name);

        if ($value === '' || filter_var($value, FILTER_VALIDATE_INT) === false) {
            return $default;
        }

        return min(max((int) $value, $minimum), $maximum);
    }
}

