#!/usr/bin/env bash

set -euo pipefail
unset CDPATH
if [[ $# -ne 1 ]]; then
    echo "Usage: bash tools/ci/test-shell-paths.sh <RC target or ''>" >&2
    exit 2
fi
target="$1"

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
temporary_root="$(cd "${TMPDIR:-/tmp}" && pwd -P)"
scratch="$(mktemp -d "$temporary_root/slug-shell-paths.XXXXXXXX")"
trap 'rm -rf -- "$scratch"' EXIT
fixture="$scratch/checkout with spaces"
export GIT_NO_REPLACE_OBJECTS=1

git -c core.hooksPath=/dev/null clone --quiet --shared --no-hardlinks -- "$repo_root" "$fixture"
# Exercise working-tree script edits too, without changing the real source index.
for script in run-gate.sh run-integration.sh verify-release-artifact.sh; do
    cp "$repo_root/tools/ci/$script" "$fixture/tools/ci/$script"
done
if ! git -C "$fixture" diff --quiet; then
    git -C "$fixture" add -- tools/ci/run-gate.sh tools/ci/run-integration.sh tools/ci/verify-release-artifact.sh
    git -C "$fixture" -c core.hooksPath=/dev/null -c user.name='Shell Path Fixture' -c user.email='fixture@example.invalid' \
        -c commit.gpgsign=false commit --quiet -m 'Apply synthetic shell path fixture'
fi
sha="$(git -C "$fixture" rev-parse HEAD)"
minor="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
caller="$scratch/caller with spaces"
mkdir -p "$caller/path temporary" "$fixture/vendor/path temporary"
failures=0
cases=0
for invocation in relative absolute; do
    working_directory="$caller"
    script="$fixture/tools/ci/run-gate.sh"
    relative_tmp='path temporary'
    if [[ "$invocation" == relative ]]; then
        working_directory="$fixture"
        script='tools/ci/run-gate.sh'
        relative_tmp='vendor/path temporary'
    fi
    for cdpath_mode in empty populated; do
        cdpath_value=''
        if [[ "$cdpath_mode" == populated ]]; then
            cdpath_value=".:$scratch"
        fi
        for tmp_mode in absolute relative; do
            tmp_value="$working_directory/$relative_tmp"
            if [[ "$tmp_mode" == relative ]]; then
                tmp_value="$relative_tmp"
            fi
            for gate in php-runtime release-artifact; do
                cases=$((cases + 1))
                log="$scratch/case-$cases.log"
                gate_arguments=(php-runtime "$minor")
                if [[ "$gate" == release-artifact ]]; then
                    gate_arguments=(release-artifact "$sha" "$target")
                fi
                if (cd "$working_directory" && CDPATH="$cdpath_value" TMPDIR="$tmp_value" bash "$script" "${gate_arguments[@]}") >"$log" 2>&1; then
                    printf 'PASS %s invocation=%s CDPATH=%s TMPDIR=%s\n' "$gate" "$invocation" "$cdpath_mode" "$tmp_mode"
                else
                    failures=$((failures + 1))
                    printf 'FAIL %s invocation=%s CDPATH=%s TMPDIR=%s\n' "$gate" "$invocation" "$cdpath_mode" "$tmp_mode" >&2
                    cat "$log" >&2
                fi
            done
        done
    done
done
printf 'Shell path regression: %s cases; %s failures.\n' "$cases" "$failures"
((failures == 0))
