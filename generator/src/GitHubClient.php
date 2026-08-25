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
        $payload = $this->fetchRuns($owner, $repository, $workflow);
        $run = $payload['workflow_runs'][0] ?? null;

        if (!is_array($run)) {
            return WorkflowState::UNKNOWN;
        }

        $defaultBranch = $run['repository']['default_branch'] ?? null;
        $headBranch = $run['head_branch'] ?? null;

        // An unfiltered workflow-runs request can return a PR or feature-branch run. The run
        // includes the repository's default branch, so only make a second API request when the
        // newest run is not the production branch we want to represent.
        if (
            is_string($defaultBranch)
            && $defaultBranch !== ''
            && $headBranch !== $defaultBranch
        ) {
            $payload = $this->fetchRuns($owner, $repository, $workflow, $defaultBranch);
            $run = $payload['workflow_runs'][0] ?? null;
        }

        return is_array($run) ? $this->mapRunToState($run) : WorkflowState::UNKNOWN;
    }

    /** @return array<string, mixed> */
    private function fetchRuns(
        string $owner,
        string $repository,
        string $workflow,
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

        if (
            !is_array($payload)
            || !isset($payload['workflow_runs'])
            || !is_array($payload['workflow_runs'])
        ) {
            throw new \RuntimeException('GitHub returned an unexpected response.');
        }

        return $payload;
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
