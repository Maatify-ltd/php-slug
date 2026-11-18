#!/usr/bin/env bash

set -euo pipefail
unset CDPATH

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
if [[ $# -ne 2 || ! "$1" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)-rc\.(0|[1-9][0-9]*)$ || ! "$2" =~ ^[0-9a-f]{40}$ ]]; then
    echo 'Usage: bash tools/ci/verify-published-artifact.sh <x.y.z-rc.N> <full qualified Git SHA>' >&2
    exit 2
fi
target="$1"
reference="$2"
export GIT_NO_REPLACE_OBJECTS=1
if [[ "$(git -C "$repo_root" rev-parse --verify "refs/tags/v$target^{commit}")" != "$reference" ]]; then
    echo 'The local release tag does not match the expected qualified commit.' >&2
    exit 1
fi

temp_root="${TMPDIR:-/tmp}"
if [[ "$temp_root" != /* ]]; then
    temp_root="./$temp_root"
fi
temp_root="$(cd -- "$temp_root" && pwd -P)"
export TMPDIR="$temp_root"
scratch="$(mktemp -d "$temp_root/slug-published-artifact.XXXXXXXX")"
trap 'status=$?; rm -rf -- "$scratch"; if [[ $status -ne 0 ]]; then echo "PAV=NOT PASS" >&2; fi' EXIT
mkdir -p "$scratch"/{home,cache,tmp,project}
cd "$scratch/project"

# Remove inherited Composer configuration, repositories, credentials, install overrides and caches.
clean_env=(env -i "PATH=$PATH" "HOME=$scratch/home" "TMPDIR=$scratch/tmp"
    "COMPOSER_HOME=$scratch/home/composer" "COMPOSER_CACHE_DIR=$scratch/cache")
printf 'PAV_TARGET=%s\nPAV_EXPECTED_REFERENCE=%s\n' "$target" "$reference"
"${clean_env[@]}" composer --version
git -C "$repo_root" show "$reference:composer.json" > "$scratch/release-composer.json"
php "$repo_root/tools/ci/verify-published-artifact.php" prepare "$target" "$scratch/release-composer.json" > composer.json
printf 'PAV_CONFIG=%s\n' "$(cat composer.json)"
printf '%s\n' 'PAV_COMMAND=composer --no-interaction --no-plugins --no-scripts update --prefer-dist --no-progress'
"${clean_env[@]}" composer --no-interaction --no-plugins --no-scripts update --prefer-dist --no-progress
git -C "$repo_root" ls-tree -rz "$reference" > "$scratch/release-tree"
php "$repo_root/tools/ci/verify-published-artifact.php" verify "$target" "$reference" "$scratch/release-tree" "$scratch/project"
