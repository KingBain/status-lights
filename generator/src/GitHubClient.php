<?php

declare(strict_types=1);

namespace StatusLights;

final class GitHubClient implements WorkflowStatusProvider
{
    public function __construct(
        private readonly int $timeout,
        private readonly ?string $token = null,
    ) {
    }

    public function fetchState(string $owner, string $repository, string $workflow): string
    {
        $url = sprintf(
            'https://api.github.com/repos/%s/%s/actions/workflows/%s/runs?per_page=1',
            rawurlencode($owner),
            rawurlencode($repository),
            rawurlencode($workflow),
        );
        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: statuslights.dev',
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $handle = curl_init($url);

        if ($handle === false) {
            throw new \RuntimeException('Unable to initialize the GitHub request.');
        }

        curl_setopt_array($handle, [
            CURLOPT_CONNECTTIMEOUT => min(3, $this->timeout),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $body = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($body)) {
            throw new \RuntimeException('GitHub request failed: ' . ($error ?: 'unknown error'));
        }

        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf('GitHub returned HTTP %d.', $statusCode));
        }

        try {
            $payload = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('GitHub returned invalid JSON.', previous: $exception);
        }

        if (!is_array($payload) || !isset($payload['workflow_runs']) || !is_array($payload['workflow_runs'])) {
            throw new \RuntimeException('GitHub returned an unexpected response.');
        }

        $run = $payload['workflow_runs'][0] ?? null;

        return is_array($run) ? $this->mapRunToState($run) : WorkflowState::UNKNOWN;
    }

    /** @param array<string, mixed> $run */
    public function mapRunToState(array $run): string
    {
        $status = strtolower((string) ($run['status'] ?? ''));

        if ($status !== '' && $status !== 'completed') {
            return WorkflowState::RUNNING;
        }

        $conclusion = strtolower((string) ($run['conclusion'] ?? ''));

        return match ($conclusion) {
            'success' => WorkflowState::SUCCESS,
            'failure', 'cancelled', 'timed_out', 'action_required', 'startup_failure', 'stale'
                => WorkflowState::FAILURE,
            default => WorkflowState::UNKNOWN,
        };
    }
}

