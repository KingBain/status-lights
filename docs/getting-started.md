# Getting started with Status Lights

Status Lights is a free, installable GitHub App that turns GitHub Actions workflow and job results
into compact SVG indicators. The App receives status changes from GitHub through signed webhooks;
loading an SVG does not poll the GitHub API.

## Before you start

You need:

- a public GitHub repository that uses GitHub Actions;
- permission to install a GitHub App on the account or organization that owns the repository; and
- the workflow filename, such as `pages.yml` or `ci.yaml`.

Status-light URLs are public and unauthenticated. Status Lights does not currently provide private
repository support, so install the public service only on public repositories.

## 1. Install the GitHub App

Open the [Status Lights page on GitHub](https://github.com/apps/status-lights) and choose
**Install**. Select only the public repositories whose workflow status you want to publish.

The App requests the smallest permission set needed by the service:

| Repository access | Level | Used for |
| --- | --- | --- |
| Actions | Read-only | Receive workflow and job status events |
| Metadata | Read-only | Identify the repository and its default branch; granted automatically by GitHub |
| All other repository permissions | No access | Not requested or used |

Status Lights cannot push code, change workflows, merge pull requests, or modify repository
settings. It does not ask for a personal access token.

## 2. Run the workflow once

Run the workflow on the repository's default branch after installing the App. GitHub sends
`workflow_run` and `workflow_job` events as the run progresses, and Status Lights records the latest
state.

GitHub does not replay older workflow events when an App is installed. Until the first new event
arrives, the workflow or job light is gray and reports `unknown`.

Status Lights currently tracks default-branch runs. Events from other branches do not replace the
published state.

## 3. Build the URL

The easiest option is the interactive builder at
[statuslights.dev](https://statuslights.dev/#customize). Enter the owner, repository, workflow
filename, and optionally a job display name.

Workflow light:

```text
https://g.statuslights.dev/github/{owner}/{repository}/{workflow}.svg
```

Job light:

```text
https://g.statuslights.dev/github/{owner}/{repository}/{workflow}/job/{job-name}.svg
```

Use the job's display name from the Actions page or its `name:` value in the workflow, not the key
under `jobs:`. Job names are case-sensitive, and spaces or other path characters must be URL
encoded.

Example:

```text
https://g.statuslights.dev/github/KingBain/status-lights/pages.yml/job/Validate%20site.svg
```

## 4. Embed the light

Markdown image:

```markdown
![Pages workflow status](https://g.statuslights.dev/github/KingBain/status-lights/pages.yml.svg)
```

Linked to the workflow:

```markdown
[![Pages workflow status](https://g.statuslights.dev/github/KingBain/status-lights/pages.yml.svg)](https://github.com/KingBain/status-lights/actions/workflows/pages.yml)
```

The same SVG URL works in HTML, documentation sites, issue descriptions, and other tools that can
display a remote image.

## Change or remove access

Open your GitHub account or organization settings, find **Applications → Installed GitHub Apps**,
and select **Configure** beside Status Lights. From there you can change the selected repositories
or uninstall the App.

Once a repository is removed, its Status Lights URLs return the `unknown` state.

## Troubleshooting

### The light is gray or unknown

Confirm that:

1. the App is installed for that repository;
2. the workflow has run on the default branch since installation;
3. the URL uses the workflow filename, including `.yml` or `.yaml`; and
4. a job URL uses the exact, case-sensitive job display name.

### The light still shows an older result

Image proxies and browsers can cache an SVG briefly. Confirm the latest run was on the default
branch, then allow the cache to refresh.

### The URL returns an error image

Use the [website builder](https://statuslights.dev/#customize) to validate the path and options. If
the problem persists, report it in the
[Status Lights repository](https://github.com/KingBain/status-lights/issues).
