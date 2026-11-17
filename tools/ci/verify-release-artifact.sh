#!/usr/bin/env bash

set -euo pipefail

unset CDPATH

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Release Artifact Verification requires $1." >&2
        exit 1
    fi
}

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ $# -ne 2 || ! "$1" =~ ^[0-9a-f]{40}$ || ( -n "$2" && ! "$2" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)-rc\.(0|[1-9][0-9]*)$ ) ]]; then
    echo "Usage: bash tools/ci/verify-release-artifact.sh <full Git SHA> <x.y.z-rc.N or ''>" >&2
    exit 2
fi
sha="$1"
target="$2"

require_command git
export GIT_NO_REPLACE_OBJECTS=1
if [[ "$(git -C "$repo_root" rev-parse HEAD)" != "$sha" ]]; then
    echo 'Release Artifact Verification requires the supplied SHA to match checkout HEAD.' >&2
    exit 1
fi
source_status="$(git --no-optional-locks -C "$repo_root" status --porcelain --untracked-files=all)"
if [[ -n "$source_status" ]]; then
    echo 'Release Artifact Verification requires a clean source worktree and index.' >&2
    exit 1
fi
require_command php
require_command cmark-gfm
if ! php -r 'exit(extension_loaded("dom") ? 0 : 1);'; then
    echo 'Release Artifact Verification requires PHP DOM support.' >&2
    exit 1
fi
if [[ -z "$target" ]]; then
    exec php "$repo_root/tools/ci/verify-release-artifact.php" --inactive "$repo_root" "$sha"
fi

require_command composer
if ! php -r 'exit(class_exists(PharData::class) ? 0 : 1);'; then
    echo 'Release Artifact Verification requires PHP Phar support.' >&2
    exit 1
fi

temporary_root="$(cd "${TMPDIR:-/tmp}" && pwd -P)"
export TMPDIR="$temporary_root"
scratch="$(mktemp -d "$temporary_root/slug-release-artifact.XXXXXXXX")"
trap 'rm -rf -- "$scratch"' EXIT
checkout="$scratch/checkout"
export COMPOSER_HOME="$scratch/composer-home"
export COMPOSER_CACHE_DIR="$scratch/composer-cache"
unset COMPOSER_AUTH COMPOSER COMPOSER_ROOT_VERSION

printf 'RAV_SHA=%s\nRAV_TARGET=%s\n' "$sha" "$target"
composer --version
printf '%s\n' 'RAV_CHECKOUT=isolated Git clone and detached exact SHA'
git -c core.hooksPath=/dev/null clone --quiet --shared --no-checkout --no-tags -- "$repo_root" "$checkout"
git -C "$checkout" -c core.hooksPath=/dev/null -c core.autocrlf=false checkout --quiet --detach "$sha"
git -C "$checkout" ls-tree -rz "$sha" > "$scratch/candidate-tree"

printf '%s\n' 'RAV_COMMAND=git archive --format=tar <exact SHA>'
git -C "$checkout" archive --format=tar --output="$scratch/git.tar" "$sha"

printf '%s\n' 'RAV_COMMAND=composer --no-interaction --no-plugins --no-scripts archive --format=zip (clean and populated checkout)'
(
    cd "$checkout"
    composer --no-interaction --no-plugins --no-scripts archive --format=zip --file=composer-clean --dir="$scratch"

    mkdir -p vendor/fixture .phpunit.cache .idea .vscode .fleet docs/guides/.vscode
    for fixture in vendor/fixture/ignored.php .env .env.test .env.local .php-cs-fixer.cache .phpunit.cache/fixture .phpunit.result.cache \
        .idea/fixture.xml .vscode/fixture.json .fleet/fixture.json dev.iml docs/guides/.DS_Store docs/guides/.env.test docs/guides/.vscode/fixture.json; do
        printf '%s\n' 'SYNTHETIC_RELEASE_ARCHIVE_FIXTURE' > "$fixture"
    done
    printf '%s\n' '{"http-basic":{}}' > auth.json
    printf '%s\n' '{"packages":[],"packages-dev":[]}' > composer.lock
    composer --no-interaction --no-plugins --no-scripts archive --format=zip --file=composer-populated --dir="$scratch"
)

php "$repo_root/tools/ci/verify-release-artifact.php" "$checkout" "$scratch/candidate-tree" "$target" \
    "$scratch/git.tar" "$scratch/composer-clean.zip" "$scratch/composer-populated.zip"
