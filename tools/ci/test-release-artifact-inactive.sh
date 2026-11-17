#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
scratch="$(mktemp -d "${TMPDIR:-/tmp}/slug-release-inactive.XXXXXXXX")"
trap 'rm -rf -- "$scratch"' EXIT

# Inactivity checking needs the Markdown tools but can run without Composer.
inactive_path="$scratch/bin"
mkdir "$inactive_path"
for utility in bash git dirname php cmark-gfm; do
    utility_path="$(command -v "$utility")"
    ln -s "$utility_path" "$inactive_path/$utility"
done

build_fixture() {
    local fixture="$scratch/$1"
    local readme="$2"
    local changelog="$3"

    mkdir -p "$fixture/tools/ci"
    cp "$repo_root/tools/ci/"{run-gate.sh,verify-release-artifact.sh,verify-release-artifact.php,release-artifact-markdown.php} \
        "$fixture/tools/ci/"
    if [[ "$readme" != MISSING ]]; then
        printf '%s' "$readme" > "$fixture/README.md"
    fi
    if [[ "$changelog" != MISSING ]]; then
        printf '%s' "$changelog" > "$fixture/CHANGELOG.md"
    fi
    git -C "$fixture" -c core.hooksPath=/dev/null init --quiet
    git -C "$fixture" -c core.autocrlf=false add .
    git -C "$fixture" -c core.hooksPath=/dev/null -c commit.gpgSign=false \
        -c user.name='Release Artifact Fixture' -c user.email='fixture@example.invalid' \
        commit --quiet -m 'Build isolated inactive release fixture'
}

assert_gate() {
    local fixture="$scratch/$1"
    local status="$2"
    local expected="$3"
    shift 3
    local actual=0 output

    output=$(cd "$fixture" && PATH="$inactive_path" bash tools/ci/run-gate.sh release-artifact "$@" 2>&1) || actual=$?
    if [[ "$actual" != "$status" || "$output" != "$expected" ]]; then
        printf 'Inactive release fixture %s failed (expected exit %s, got %s).\n%s\n' "${fixture##*/}" "$status" "$actual" "$output" >&2
        exit 1
    fi
}

assert_inactive_fixture() {
    local name="$1"
    local status="$2"
    local expected="$3"

    build_fixture "$name" "$4" "$5"
    assert_gate "$name" "$status" "$expected" "$(git -C "$scratch/$name" rev-parse HEAD)" ''
}

readme=$'# Slug\n\nA consumer-facing package.\n'
dated=$'# Changelog\n\n## [1.0.0-rc.2] - 2026-09-25\n\n## 1.0.0-rc.1 - 2026-09-24\n'
readme_guard=$'Set RELEASE_ARTIFACT_TARGET to the exact RC being prepared.\nActive release metadata found in:\nREADME.md'
changelog_guard=$'Set RELEASE_ARTIFACT_TARGET to the exact RC being prepared.\nActive release metadata found in:\nCHANGELOG.md'
inactive='Release Artifact Verification is not applicable. No release target is active.'

assert_inactive_fixture readme-bold 1 "$readme_guard" $'# Slug\n\n**Release target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture readme-bold-title-case 1 "$readme_guard" $'# Slug\n\n**Release Target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture readme-uppercase 1 "$readme_guard" $'# Slug\n\n RELEASE TARGET: `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture changelog-plain 1 "$changelog_guard" "$readme" $'# Changelog\n\n## 1.0.0-rc.3\n'
assert_inactive_fixture changelog-bracketed 1 "$changelog_guard" "$readme" $'# Changelog\n\n## [1.0.0-rc.3]\n'
assert_inactive_fixture changelog-linked 1 "$changelog_guard" "$readme" \
    $'# Changelog\n\n## [1.0.0-rc.3]\n\n[1.0.0-rc.3]: https://example.invalid/releases/tag/v1.0.0-rc.3\n'
assert_inactive_fixture missing-readme 1 'Cannot determine release preparation state from README.md.' MISSING "$dated"
assert_inactive_fixture empty-changelog 1 'Cannot determine release preparation state from CHANGELOG.md.' "$readme" ''
assert_inactive_fixture dated-history 0 "$inactive" "$readme" "$dated"
assert_inactive_fixture hidden-multiline 0 "$inactive" "$readme" "${dated}"$'\n<!--\n## [1.0.0-rc.3]\n-->\n'
assert_inactive_fixture hidden-unclosed 0 "$inactive" "$readme" "${dated}"$'\n<!--\n## 1.0.0-rc.3\n'
assert_inactive_fixture visible-with-comment 1 "$changelog_guard" "$readme" "${dated}"$'\n<!--\n## [1.0.0-rc.4]\n-->\n## [1.0.0-rc.3] <!-- release note -->\n'
assert_inactive_fixture split-heading-markers 0 "$inactive" "$readme" "${dated}"$'\n#<!-- comment --># 1.0.0-rc.3\n'
assert_inactive_fixture split-heading-lines 0 "$inactive" "$readme" "${dated}"$'\n## <!-- comment\nends here --> 1.0.0-rc.3\n'
assert_inactive_fixture fenced-changelog 0 "$inactive" "$readme" "${dated}"$'\n```markdown\n## 1.0.0-rc.3\n```\n'
assert_inactive_fixture hidden-readme 0 "$inactive" "${readme}"$'\n<!--\n**Release target:** `1.0.0-rc.3`\n-->\n' "$dated"
assert_inactive_fixture indented-changelog 1 "$changelog_guard" "$readme" "${dated}"$'\n   ## [1.0.0-rc.3]\n'

dated_head="$(git -C "$scratch/dated-history" rev-parse HEAD)"
assert_gate dated-history 1 'Release Artifact Verification requires the supplied SHA to match checkout HEAD.' \
    0000000000000000000000000000000000000000 ''
assert_gate dated-history 2 "Usage: bash tools/ci/verify-release-artifact.sh <full Git SHA> <x.y.z-rc.N or ''>" "$dated_head"

printf '%s\n' 'SYNTHETIC_DIRTY_CHECKOUT' > "$scratch/dated-history/untracked-fixture"
assert_gate dated-history 1 'Release Artifact Verification requires a clean source worktree and index.' "$dated_head" ''

echo 'Release Artifact Verification inactive regression passed (20 cases).'
