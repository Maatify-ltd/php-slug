#!/usr/bin/env bash

set -euo pipefail
unset CDPATH

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"
temporary_root="${TMPDIR:-/tmp}"
if [[ "$temporary_root" != /* ]]; then
    temporary_root="./$temporary_root"
fi
temporary_root="$(cd -- "$temporary_root" && pwd -P)"
export TMPDIR="$temporary_root"
scratch="$(mktemp -d "$temporary_root/slug-tmpdir-entrypoints.XXXXXXXX")"
trap 'rm -rf -- "$scratch"' EXIT
fixture="$scratch/checkout with spaces"
export GIT_NO_REPLACE_OBJECTS=1

git -c core.hooksPath=/dev/null clone --quiet --shared --no-hardlinks -- "$repo_root" "$fixture"
script_paths=()
for script in run-gate.sh run-integration.sh test-shell-paths.sh \
    test-release-artifact-active.sh test-release-artifact-inactive.sh \
    verify-release-artifact.sh install-release-markdown.sh; do
    script_paths+=("tools/ci/$script")
    cp "$repo_root/tools/ci/$script" "$fixture/tools/ci/$script"
done
if ! git -C "$fixture" diff --quiet; then
    git -C "$fixture" add -- "${script_paths[@]}"
    git -C "$fixture" -c core.hooksPath=/dev/null -c user.name='TMPDIR Fixture' -c user.email='fixture@example.invalid' \
        -c commit.gpgsign=false commit --quiet -m 'Apply synthetic TMPDIR fixture'
fi
sha="$(git -C "$fixture" rev-parse HEAD)"
minor="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"

# Stop the real entry point at its first dependency call after path resolution.
# The marker and exact exit status distinguish this stop from an earlier failure.
cat > "$scratch/probe" <<'PROBE'
#!/usr/bin/env bash
set -euo pipefail
case "${0##*/}" in
    mktemp)
        [[ $# == 2 && "$1" == -d && "${2%/*}" == "$SLUG_TMPDIR_PROBE_EXPECTED" ]] || exit 96
        ;;
    php)
        [[ $# == 2 && "${1##*/}" == verify-php-runtime.php && "$2" == "$SLUG_TMPDIR_PROBE_MINOR" ]] || exit 97
        [[ "$TMPDIR" == "$SLUG_TMPDIR_PROBE_EXPECTED" && "$PWD" == "$SLUG_TMPDIR_PROBE_CHECKOUT" ]] || exit 98
        ;;
    docker)
        [[ $# == 2 && "$1" == compose && "$2" == version ]] || exit 97
        [[ "$TMPDIR" == "$SLUG_TMPDIR_PROBE_EXPECTED" && "$PWD" == "$SLUG_TMPDIR_PROBE_CHECKOUT" ]] || exit 98
        ;;
    *) exit 99 ;;
esac
printf '%s\n' 'TMPDIR_PROBE=PASS' > "$SLUG_TMPDIR_PROBE_RESULT"
exit 37
PROBE
chmod +x "$scratch/probe"
for tool in mktemp php docker; do
    mkdir "$scratch/bin-$tool"
    ln -s "$scratch/probe" "$scratch/bin-$tool/$tool"
done
# The installer stops at mktemp, before invoking a compiler or downloading source.
ln -s "$scratch/probe" "$scratch/bin-mktemp/cmake"

cases=0
failures=0
for invocation in relative absolute; do
    working_directory="$scratch/caller with spaces"
    script_prefix="$fixture/"
    if [[ "$invocation" == relative ]]; then
        working_directory="$fixture/vendor/caller with spaces"
        script_prefix='../../'
    fi
    mkdir -p "$working_directory/-temporary" "$working_directory/-" "$working_directory/path temporary" "$working_directory/physical temporary"
    ln -s "$working_directory/physical temporary" "$working_directory/linked temporary"

    for form in relative-dash-name relative-dash-only absolute-dash-name absolute-dash-only \
        relative-spaces absolute-spaces relative-symlink absolute-symlink; do
        case "$form" in
            relative-dash-name) tmp_value='-temporary'; expected_root="$working_directory/-temporary" ;;
            relative-dash-only) tmp_value='-'; expected_root="$working_directory/-" ;;
            absolute-dash-name) tmp_value="$working_directory/-temporary"; expected_root="$tmp_value" ;;
            absolute-dash-only) tmp_value="$working_directory/-"; expected_root="$tmp_value" ;;
            relative-spaces) tmp_value='path temporary'; expected_root="$working_directory/path temporary" ;;
            absolute-spaces) tmp_value="$working_directory/path temporary"; expected_root="$tmp_value" ;;
            relative-symlink) tmp_value='linked temporary'; expected_root="$working_directory/physical temporary" ;;
            absolute-symlink) tmp_value="$working_directory/linked temporary"; expected_root="$working_directory/physical temporary" ;;
        esac

        for script in run-gate.sh run-integration.sh test-shell-paths.sh \
            test-release-artifact-active.sh test-release-artifact-inactive.sh \
            verify-release-artifact.sh install-release-markdown.sh; do
            tool=mktemp
            expected_status=37
            arguments=()
            case "$script" in
                run-gate.sh) tool=php; arguments=(php-runtime "$minor") ;;
                run-integration.sh) tool=docker; expected_status=1; arguments=(integration) ;;
                test-shell-paths.sh) arguments=(1.0.0-rc.3) ;;
                verify-release-artifact.sh) arguments=("$sha" 1.0.0-rc.3) ;;
                install-release-markdown.sh) arguments=("$scratch/unused-install") ;;
            esac
            cases=$((cases + 1))
            result="$scratch/result-$cases"
            log="$scratch/log-$cases"
            actual_status=0
            (cd "$working_directory" && CDPATH=".:$scratch" TMPDIR="$tmp_value" \
                SLUG_TMPDIR_PROBE_EXPECTED="$expected_root" SLUG_TMPDIR_PROBE_RESULT="$result" \
                SLUG_TMPDIR_PROBE_CHECKOUT="$fixture" SLUG_TMPDIR_PROBE_MINOR="$minor" \
                PATH="$scratch/bin-$tool:$PATH" \
                bash "${script_prefix}tools/ci/$script" ${arguments[@]+"${arguments[@]}"}) >"$log" 2>&1 || actual_status=$?
            if [[ "$actual_status" == "$expected_status" && -f "$result" ]] && grep -Fxq 'TMPDIR_PROBE=PASS' "$result"; then
                printf 'PASS entrypoint=%s invocation=%s TMPDIR=%s\n' "$script" "$invocation" "$form"
            else
                failures=$((failures + 1))
                printf 'FAIL entrypoint=%s invocation=%s TMPDIR=%s (exit %s, expected controlled stop %s)\n' \
                    "$script" "$invocation" "$form" "$actual_status" "$expected_status" >&2
                cat "$log" >&2
            fi
        done
    done
done
printf 'TMPDIR entry-point probes: %s cases; %s failures (controlled stops, not full dependency execution).\n' "$cases" "$failures"
((failures == 0))
