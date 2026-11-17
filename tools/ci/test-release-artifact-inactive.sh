#!/usr/bin/env bash

set -euo pipefail

unset CDPATH

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
temporary_root="$(cd "${TMPDIR:-/tmp}" && pwd -P)"
export TMPDIR="$temporary_root"
scratch="$(mktemp -d "$temporary_root/slug-release-inactive.XXXXXXXX")"
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
    local readme_ar="${4-$arabic_readme}"

    mkdir -p "$fixture/tools/ci"
    cp "$repo_root/tools/ci/"{run-gate.sh,verify-release-artifact.sh,verify-release-artifact.php,release-artifact-markdown.php,release-artifact-files.php} \
        "$fixture/tools/ci/"
    if [[ "$readme" != MISSING ]]; then
        printf '%s' "$readme" > "$fixture/README.md"
    fi
    if [[ "$readme_ar" != MISSING ]]; then
        printf '%s' "$readme_ar" > "$fixture/README_AR.md"
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

    shift 3
    build_fixture "$name" "$@"
    assert_gate "$name" "$status" "$expected" "$(git -C "$scratch/$name" rev-parse HEAD)" ''
}

readme=$'# Slug\n\nA consumer-facing package.\n'
arabic_readme=$'# Slug\n\nمكتبة قابلة لإعادة الاستخدام.\n'
dated=$'# Changelog\n\n## [1.0.0-rc.2] - 2026-09-25\n\n## 1.0.0-rc.1 - 2026-09-24\n'
readme_guard=$'Set RELEASE_ARTIFACT_TARGET to the exact RC being prepared.\nActive release metadata found in:\nREADME.md'
arabic_guard=$'Set RELEASE_ARTIFACT_TARGET to the exact RC being prepared.\nActive release metadata found in:\nREADME_AR.md'
active_arabic_readme=$'# Slug\n\n## حالة النشر\n\n**الإصدار المستهدف:** `1.0.0-rc.3`\n'
changelog_guard=$'Set RELEASE_ARTIFACT_TARGET to the exact RC being prepared.\nActive release metadata found in:\nCHANGELOG.md'
inactive='Release Artifact Verification is not applicable. No release target is active.'

assert_inactive_fixture readme-bold 1 "$readme_guard" $'# Slug\n\n**Release target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture readme-bold-title-case 1 "$readme_guard" $'# Slug\n\n**Release Target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture readme-uppercase 1 "$readme_guard" $'# Slug\n\n RELEASE TARGET: `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture readme-later-in-paragraph 1 "$readme_guard" \
    $'# Slug\n\nA consumer-facing package. **Release target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture readme-after-softbreak 1 "$readme_guard" \
    $'# Slug\n\nA consumer-facing package.\n**Release target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture inline-code-readme-label 0 "$inactive" \
    $'# Slug\n\n`Release target:` is a field in the preparation template.\n' "$dated"
assert_inactive_fixture readme-list-marker 1 "$readme_guard" \
    $'# Slug\n\n- **Release target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture readme-nested-list-marker 1 "$readme_guard" \
    $'# Slug\n\n- Package metadata\n  1. **Release target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture readme-table-marker 1 "$readme_guard" \
    $'# Slug\n\n| Package metadata |\n| --- |\n| **Release target:** `1.0.0-rc.3` |\n' "$dated"
assert_inactive_fixture readme-alert-marker 1 "$readme_guard" \
    $'# Slug\n\n> [!NOTE]\n> **Release target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture readme-inline-alert-example 0 "$inactive" \
    "$readme"$'\n> [!NOTE] **Release target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture readme-publication-quote 1 "$readme_guard" \
    $'# Slug\n\n## Publication State\n\n> **Release target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture readme-publication-quote-example 0 "$inactive" \
    $'# Slug\n\n## Publication State\n\nNo target is in preparation.\n\n## Historical release examples\n\n> **Release target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture readme-publication-quote-under-h3 1 "$readme_guard" \
    $'# Slug\n\n## Publication State\n\n### Example\n\n> **Release target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture arabic-only-active 1 "$arabic_guard" "$readme" "$dated" "$active_arabic_readme"
assert_inactive_fixture arabic-uppercase-version-active 1 "$arabic_guard" "$readme" "$dated" \
    "${active_arabic_readme/1.0.0-rc.3/1.0.0-RC.3}"
assert_inactive_fixture arabic-publication-quote 1 "$arabic_guard" "$readme" "$dated" \
    $'# Slug\n\n## حالة النشر\n\n> **الإصدار المستهدف:** `1.0.0-rc.3`\n'
assert_inactive_fixture arabic-historical-quote 0 "$inactive" "$readme" "$dated" \
    "$arabic_readme"$'\n## أمثلة الإصدارات السابقة\n\n> **الإصدار المستهدف:** `1.0.0-rc.2`\n'
assert_inactive_fixture arabic-fenced-example 0 "$inactive" "$readme" "$dated" \
    "$arabic_readme"$'\n## حالة النشر\n\n```markdown\n**الإصدار المستهدف:** `1.0.0-rc.3`\n```\n'
assert_inactive_fixture arabic-missing-readme 1 'Cannot determine release preparation state from README_AR.md.' "$readme" "$dated" MISSING
assert_inactive_fixture readme-publication-raw-html 1 \
    'README.md publication-state section must use Markdown. Raw HTML blocks are not allowed.' \
    $'# Slug\n\n## Publication State\n\n<p><strong>Release target:</strong> <code>1.0.0-rc.3</code></p>\n' "$dated"
assert_inactive_fixture readme-publication-quote-hidden 0 "$inactive" \
    $'# Slug\n\n## Publication State\n\nNo target is in preparation.\n\n<!--\n> **Release target:** `1.0.0-rc.3`\n-->\n' "$dated"
assert_inactive_fixture readme-list-examples 0 "$inactive" \
    "$readme"$'\n- Template example:\n\n  ```markdown\n  **Release target:** `1.0.0-rc.3`\n  ```\n\n- Quoted example:\n\n  > **Release target:** `1.0.0-rc.3`\n' "$dated"
assert_inactive_fixture changelog-plain 1 "$changelog_guard" "$readme" $'# Changelog\n\n## 1.0.0-rc.3\n'
assert_inactive_fixture changelog-bracketed 1 "$changelog_guard" "$readme" $'# Changelog\n\n## [1.0.0-rc.3]\n'
assert_inactive_fixture changelog-linked 1 "$changelog_guard" "$readme" \
    $'# Changelog\n\n## [1.0.0-rc.3]\n\n[1.0.0-rc.3]: https://example.invalid/releases/tag/v1.0.0-rc.3\n'
assert_inactive_fixture missing-readme 1 'Cannot determine release preparation state from README.md.' MISSING "$dated"
assert_inactive_fixture empty-changelog 1 'Cannot determine release preparation state from CHANGELOG.md.' "$readme" ''
assert_inactive_fixture blank-readme 1 'Cannot determine release preparation state from README.md.' $' \t\n\n' "$dated"
assert_inactive_fixture blank-changelog 1 'Cannot determine release preparation state from CHANGELOG.md.' "$readme" $' \t\n\n'
assert_inactive_fixture comment-only-readme 1 'Cannot determine release preparation state from README.md.' \
    $'<!--\nOnly a comment.\n-->\n' "$dated"
assert_inactive_fixture comment-only-changelog 1 'Cannot determine release preparation state from CHANGELOG.md.' \
    "$readme" $'<!--\nOnly a comment.\n-->\n'
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

assert_assume_unchanged_readme() {
    local name="$1"
    local status="$2"
    local expected="$3"
    local file="$4"
    local fixture="$scratch/$name"

    if [[ "$file" == README_AR.md ]]; then
        build_fixture "$name" "$readme" "$dated" "$5"
    else
        build_fixture "$name" "$5" "$dated"
    fi
    git -C "$fixture" update-index --assume-unchanged "$file"
    printf '%s' "$6" > "$fixture/$file"
    if [[ -n "$(git --no-optional-locks -C "$fixture" status --porcelain)" ]]; then
        echo 'The inactive fixture must hide its modified README from git status.' >&2
        exit 1
    fi
    assert_gate "$name" "$status" "$expected" "$(git -C "$fixture" rev-parse HEAD)" ''
}

active_readme=$'# Slug\n\n**Release target:** `1.0.0-rc.3`\n'
assert_assume_unchanged_readme committed-active 1 "$readme_guard" README.md "$active_readme" "$readme"
assert_assume_unchanged_readme committed-inactive 0 "$inactive" README.md "$readme" "$active_readme"
assert_assume_unchanged_readme committed-arabic-active 1 "$arabic_guard" README_AR.md "$active_arabic_readme" "$arabic_readme"
assert_assume_unchanged_readme committed-arabic-inactive 0 "$inactive" README_AR.md "$arabic_readme" "$active_arabic_readme"

(
    unset GIT_NO_REPLACE_OBJECTS
    build_fixture replaced-readme-blob "$active_readme" "$dated"
    replaced_blob_fixture="$scratch/replaced-readme-blob"
    replaced_blob_head="$(git -C "$replaced_blob_fixture" rev-parse HEAD)"
    assert_gate replaced-readme-blob 1 "$readme_guard" "$replaced_blob_head" ''
    original_blob="$(git -C "$replaced_blob_fixture" rev-parse "$replaced_blob_head:README.md")"
    replacement_blob="$(printf '%s' "$readme" | git -C "$replaced_blob_fixture" hash-object -w --stdin)"
    git -C "$replaced_blob_fixture" replace "$original_blob" "$replacement_blob"
    if [[ "$(git -C "$replaced_blob_fixture" cat-file blob "$original_blob")" != "${readme%$'\n'}" ||
        -n "$(git --no-optional-locks -C "$replaced_blob_fixture" status --porcelain)" ]]; then
        echo 'The inactive fixture must replace the README blob without changing the clean checkout.' >&2
        exit 1
    fi
    assert_gate replaced-readme-blob 1 "$readme_guard" "$replaced_blob_head" ''

    # Materialize a replacement tree in a separate detached fixture and verify its dirty status.
    replaced_commit_fixture="$scratch/replaced-readme-commit"
    git --no-replace-objects -c core.hooksPath=/dev/null clone --quiet --shared --no-tags -- \
        "$replaced_blob_fixture" "$replaced_commit_fixture"
    git --no-replace-objects -C "$replaced_commit_fixture" -c core.hooksPath=/dev/null \
        checkout --quiet --detach "$replaced_blob_head"
    printf '%s' "$readme" > "$replaced_commit_fixture/README.md"
    git -C "$replaced_commit_fixture" add README.md
    replacement_tree="$(git -C "$replaced_commit_fixture" write-tree)"
    replacement_commit="$(git -C "$replaced_commit_fixture" -c user.name='Release Artifact Fixture' \
        -c user.email='fixture@example.invalid' -c commit.gpgSign=false commit-tree "$replacement_tree" \
        -m 'Build synthetic Git replacement commit')"
    git -C "$replaced_commit_fixture" replace "$replaced_blob_head" "$replacement_commit"
    if [[ -n "$(git --no-optional-locks -C "$replaced_commit_fixture" status --porcelain)" ]]; then
        echo 'The inactive fixture must make the materialized replacement appear clean to Git.' >&2
        exit 1
    fi
    assert_gate replaced-readme-commit 1 'Release Artifact Verification requires a clean source worktree and index.' \
        "$replaced_blob_head" ''
)

echo 'Release Artifact Verification inactive regression passed (51 cases).'
