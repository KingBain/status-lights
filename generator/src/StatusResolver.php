<?php

declare(strict_types=1);

namespace StatusLights;

final class StatusResolver
{
    public function __construct(
        private readonly WorkflowStatusProvider $provider,
        private readonly FileCache $cache,
        private readonly int $cacheTtl,
        private readonly int $staleTtl,
    ) {
    }

    public function resolve(GeneratorRequest $request, ?int $now = null): StatusResult
    {
        $now ??= time();
        $key = strtolower(implode('/', [
            $request->owner,
            $request->repository,
            $request->workflow,
        ]));
        $cached = $this->cache->read($key);

        if ($cached !== null && ($now - $cached['fetched_at']) <= $this->cacheTtl) {
            return new StatusResult($cached['state'], 'hit', $cached['fetched_at']);
        }

        try {
            $state = $this->provider->fetchState(
                $request->owner,
                $request->repository,
                $request->workflow,
            );
            $this->cache->write($key, $state, $now);

            return new StatusResult($state, 'miss', $now);
        } catch (\Throwable) {
            if ($cached !== null && ($now - $cached['fetched_at']) <= $this->staleTtl) {
                return new StatusResult($cached['state'], 'stale', $cached['fetched_at']);
            }

            return new StatusResult(WorkflowState::UNKNOWN, 'error', $now);
        }
    }
}

