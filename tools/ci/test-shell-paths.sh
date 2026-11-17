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
export TMPDIR="$temporary_root"
scratch="$(mktemp -d "$temporary_root/slug-shell-paths.XXXXXXXX")"
trap 'rm -rf -- "$scratch"' EXIT
fixture="$scratch/checkout with spaces"
export GIT_NO_REPLACE_OBJECTS=1

git -c core.hooksPath=/dev/null clone --quiet --shared --no-hardlinks -- "$repo_root" "$fixture"
# Exercise working-tree shell edits without changing the real source index.
script_paths=()
for script in run-gate.sh run-integration.sh verify-release-artifact.sh \
    test-release-artifact-active.sh test-release-artifact-inactive.sh \
    install-release-markdown.sh verify-published-artifact.sh; do
    script_paths+=("tools/ci/$script")
    cp "$repo_root/tools/ci/$script" "$fixture/tools/ci/$script"
done
if ! git -C "$fixture" diff --quiet; then
    git -C "$fixture" add -- "${script_paths[@]}"
    git -C "$fixture" -c core.hooksPath=/dev/null -c user.name='Shell Path Fixture' -c user.email='fixture@example.invalid' \
        -c commit.gpgsign=false commit --quiet -m 'Apply synthetic shell path fixture'
fi
sha="$(git -C "$fixture" rev-parse HEAD)"
minor="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
caller="$scratch/caller with spaces"
mkdir -p "$caller/path temporary" "$fixture/vendor/path temporary"
failures=0
cases=0
artifact_marker='RAV=PASS'
if [[ -z "$target" ]]; then
    artifact_marker='RAV=N/A'
fi

# Require the real command's outcome and reject the reported Composer temp-directory failure.
assert_command() {
    local label="$1"
    local expected="$2"
    shift 2
    local log actual_status=0
    cases=$((cases + 1))
    log="$scratch/case-$cases.log"
    (cd "$working_directory" && CDPATH="$cdpath_value" TMPDIR="$tmp_value" "$@") >"$log" 2>&1 || actual_status=$?
    if ((actual_status == 0)) \
        && { [[ -z "$expected" ]] || grep -Fq -- "$expected" "$log"; } \
        && ! grep -Eq 'PHP temp directory .* does not exist or is not writable' "$log"; then
        printf 'PASS %s\n' "$label"
    else
        failures=$((failures + 1))
        printf 'FAIL %s (exit %s)\n' "$label" "$actual_status" >&2
        cat "$log" >&2
    fi
}

for invocation in relative absolute; do
    working_directory="$caller"
    script_prefix="$fixture/"
    relative_tmp='path temporary'
    if [[ "$invocation" == relative ]]; then
        working_directory="$fixture"
        script_prefix=''
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
            assert_command "php-runtime invocation=$invocation CDPATH=$cdpath_mode TMPDIR=$tmp_mode" '' \
                bash "${script_prefix}tools/ci/run-gate.sh" php-runtime "$minor"
            assert_command "release-artifact invocation=$invocation CDPATH=$cdpath_mode TMPDIR=$tmp_mode" "$artifact_marker" \
                bash "${script_prefix}tools/ci/run-gate.sh" release-artifact "$sha" "$target"
        done
    done

    # These entry points must work directly, without run-gate.sh repairing their environment first.
    cdpath_value=".:$scratch"
    for tmp_mode in absolute relative; do
        tmp_value="$working_directory/$relative_tmp"
        if [[ "$tmp_mode" == relative ]]; then
            tmp_value="$relative_tmp"
        fi
        assert_command "direct-active invocation=$invocation TMPDIR=$tmp_mode" 'Release Artifact Verification active regression passed (' \
            bash "${script_prefix}tools/ci/test-release-artifact-active.sh"
        assert_command "direct-inactive invocation=$invocation TMPDIR=$tmp_mode" 'Release Artifact Verification inactive regression passed (' \
            bash "${script_prefix}tools/ci/test-release-artifact-inactive.sh"
        assert_command "direct-archive invocation=$invocation TMPDIR=$tmp_mode" "$artifact_marker" \
            bash "${script_prefix}tools/ci/verify-release-artifact.sh" "$sha" "$target"
    done
done
printf 'Shell path regression: %s cases; %s failures.\n' "$cases" "$failures"
((failures == 0))
