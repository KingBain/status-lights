#!/usr/bin/env bash

set -euo pipefail

readonly repository_path="${STATUS_LIGHTS_REPOSITORY_PATH:-/home/kingbain/g.statuslights.dev/gh}"
readonly app_store_path="${STATUS_LIGHTS_APP_STORE_DIR:-/home/kingbain/g.statuslights.dev/app-data}"
readonly branch="${STATUS_LIGHTS_BRANCH:-main}"
readonly git_bin="${STATUS_LIGHTS_GIT_BIN:-/usr/local/cpanel/3rdparty/bin/git}"
readonly php_bin="${STATUS_LIGHTS_PHP_BIN:-/usr/local/bin/ea-php84}"
readonly uapi_bin="${STATUS_LIGHTS_UAPI_BIN:-/usr/local/cpanel/bin/uapi}"

if [[ ! -d "${repository_path}/.git" ]]; then
    printf 'Status Lights repository not found at %s.\n' "${repository_path}" >&2
    exit 1
fi

if [[ ! -x "${git_bin}" ]]; then
    printf 'cPanel Git executable not found at %s.\n' "${git_bin}" >&2
    exit 1
fi

if [[ ! -x "${uapi_bin}" ]]; then
    printf 'cPanel UAPI executable not found at %s.\n' "${uapi_bin}" >&2
    exit 1
fi

if [[ ! -x "${php_bin}" ]]; then
    printf 'cPanel PHP executable not found at %s.\n' "${php_bin}" >&2
    exit 1
fi

if [[ -n "$("${git_bin}" -C "${repository_path}" status --porcelain)" ]]; then
    printf 'Refusing to pull because the cPanel repository has uncommitted changes.\n' >&2
    exit 1
fi

before_commit="$("${git_bin}" -C "${repository_path}" rev-parse HEAD)"

if ! pull_output="$(
    "${git_bin}" -C "${repository_path}" pull --ff-only origin "${branch}" 2>&1
)"; then
    printf 'Unable to pull Status Lights from GitHub:\n%s\n' "${pull_output}" >&2
    exit 1
fi

after_commit="$("${git_bin}" -C "${repository_path}" rev-parse HEAD)"

if [[ "${before_commit}" != "${after_commit}" ]]; then
    "${uapi_bin}" VersionControlDeployment create repository_root="${repository_path}"
    printf 'Deployed Status Lights commit %s.\n' "${after_commit}"
fi

STATUS_LIGHTS_APP_STORE_DIR="${app_store_path}" \
    "${php_bin}" "${repository_path}/scripts/prune-app-runs.php"
