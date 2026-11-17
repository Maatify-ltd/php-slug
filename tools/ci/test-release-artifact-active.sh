#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
target='1.0.0-rc.3'
scratch="$(mktemp -d "${TMPDIR:-/tmp}/slug-release-active.XXXXXXXX")"
trap 'rm -rf -- "$scratch"' EXIT
base="$scratch/base"

commit_fixture() {
    git -C "$1" -c core.autocrlf=false add .
    git -C "$1" -c core.hooksPath=/dev/null -c commit.gpgSign=false \
        -c user.name='Release Artifact Fixture' -c user.email='fixture@example.invalid' \
        commit --quiet --allow-empty -m 'Build isolated active release fixture'
}

mkdir -p "$base"/{tools/ci,src,schema/mysql,docs/guides,examples}
cp "$repo_root/"{.gitattributes,.gitignore,CONTRIBUTING.md,CODE_OF_CONDUCT.md,LICENSE,SLUG_PACKAGE_REFERENCE.md,llms.txt} \
    "$base/"
cp "$repo_root/tools/ci/"{run-gate.sh,verify-release-artifact.sh,verify-release-artifact.php,release-artifact-markdown.php} \
    "$base/tools/ci/"

# The fixture has fixed release documents and dependency identity in every repository phase.
php -r '
    $manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    $fixture = [
        "name" => "maatify/php-slug",
        "description" => "Synthetic release archive fixture for Slug.",
        "require" => ["maatify/php-persistence" => "1.0.0-rc.1"],
        "archive" => $manifest["archive"],
        "minimum-stability" => "stable",
        "prefer-stable" => true,
    ];
    echo json_encode($fixture, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
' "$repo_root/composer.json" > "$base/composer.json"
cat > "$base/README.md" <<'MARKDOWN'
# Slug

[![Version](https://img.shields.io/badge/Version-1.0.0--rc.3-blue.svg)](#publication-state)
[![Install after publication](https://img.shields.io/badge/Install%20after%20publication-1.0.0--rc.3%40RC-blue.svg)](#installation)

## Publication State

**Release target:** `1.0.0-rc.3`, a Pre-Stable Release Candidate.

At qualification, `1.0.0-rc.3` is unpublished.

## Installation

```bash
composer require maatify/php-slug:1.0.0-rc.3@RC maatify/php-persistence:1.0.0-rc.1@RC
```
MARKDOWN
cat > "$base/README_AR.md" <<'MARKDOWN'
# Slug

## التثبيت

~~~bash
composer require maatify/php-slug:1.0.0-rc.3@RC maatify/php-persistence:1.0.0-rc.1@RC
~~~
MARKDOWN
cat > "$base/SECURITY.md" <<'MARKDOWN'
# Security Policy

This artifact targets `1.0.0-rc.3` as a Pre-Stable Release Candidate.

| Version | Supported |
| --- | --- |
| `1.0.0-rc.3` pre-release (artifact target) | No |
MARKDOWN
printf '%s\n' '# Changelog' '' '## 1.0.0-rc.3' > "$base/CHANGELOG.md"
printf '<?php\n' > "$base/src/Fixture.php"
printf 'SELECT 1;\n' > "$base/schema/mysql/fixture.sql"
printf '# Distribution fixture\n' > "$base/docs/guides/FIXTURE.md"
printf '<?php\n' > "$base/examples/fixture.php"
git -C "$base" -c core.hooksPath=/dev/null init --quiet
commit_fixture "$base"

gate_cases=0
assert_gate() {
    local fixture="$1"
    local expected_status="$2"
    local expected_output="$3"
    local sha actual=0 output archive

    sha="$(git -C "$fixture" rev-parse HEAD)"
    output=$(cd "$fixture" && bash tools/ci/run-gate.sh release-artifact "$sha" "$target" 2>&1) || actual=$?
    if [[ "$actual" != "$expected_status" ]] || ! grep -Fq -- "$expected_output" <<< "$output"; then
        printf 'Active release fixture %s failed (expected exit %s and %s, got %s).\n%s\n' \
            "${fixture##*/}" "$expected_status" "$expected_output" "$actual" "$output" >&2
        exit 1
    fi
    if [[ "$expected_status" == 0 ]]; then
        for archive in GIT_TAR COMPOSER_ZIP POPULATED_COMPOSER_ZIP; do
            if ! grep -Eq "^RAV_${archive}_SHA256=[0-9a-f]{64}$" <<< "$output"; then
                printf 'Active release fixture %s lacks %s archive evidence.\n' "${fixture##*/}" "$archive" >&2
                exit 1
            fi
        done
    fi
    if [[ -n "$(git --no-optional-locks -C "$fixture" status --porcelain --untracked-files=all)" ]]; then
        printf 'Active release fixture %s was modified by the gate.\n' "${fixture##*/}" >&2
        exit 1
    fi
    gate_cases=$((gate_cases + 1))
}

new_fixture() {
    git -c core.hooksPath=/dev/null clone --quiet --shared --no-tags -- "$base" "$scratch/$1"
}

pass='RAV=PASS'
changelog_failure='CHANGELOG.md must put the exact undated target in its first release section.'
changelog_fixture() {
    local name="$1"
    local status="$2"
    local expected="$3"
    shift 3
    local fixture="$scratch/$name"

    new_fixture "$name"
    printf '%s\n' '# Changelog' '' "$@" > "$fixture/CHANGELOG.md"
    commit_fixture "$fixture"
    assert_gate "$fixture" "$status" "$expected"
}

assert_gate "$base" 0 "$pass"
changelog_fixture bracketed 0 "$pass" "## [$target]"
changelog_fixture empty-unreleased 0 "$pass" '## [Unreleased]' '' "## $target"
changelog_fixture empty-unreleased-and-bracketed 0 "$pass" '## [Unreleased]' '' "## [$target]"
changelog_fixture linked-unreleased 0 "$pass" '## [Unreleased]' '' "## [$target]" '' \
    '[Unreleased]: https://example.invalid/compare/v1.0.0-rc.2...HEAD'
changelog_fixture linked-target 1 "$changelog_failure" "## [$target]" '' \
    "[$target]: https://example.invalid/releases/tag/v$target"
changelog_fixture dated 1 "$changelog_failure" "## $target - 2026-11-17"
changelog_fixture duplicate-dated-target 1 'CHANGELOG.md contains more than one section for the exact release target.' \
    "## $target" '' "## [$target] - 2026-11-17"
changelog_fixture duplicate-parenthetical-date 1 'CHANGELOG.md contains more than one section for the exact release target.' \
    "## $target" '' "## $target (2026-11-17)"
changelog_fixture different-dated-target 0 "$pass" "## $target" '' '## [1.0.0-rc.30] - 2026-11-17'
changelog_fixture wrong 1 "$changelog_failure" '## 9999.9999.9999-rc.9999' '' "## $target"
changelog_fixture missing 1 "$changelog_failure" 'No release sections.'
changelog_fixture hidden-html 1 "$changelog_failure" '<!--' "## $target" '-->'
changelog_fixture hidden-unclosed 1 "$changelog_failure" '<!--' "## $target"
changelog_fixture visible-html 0 "$pass" '<!--' '## 9999.9999.9999-rc.9999' '-->' "## [$target] <!-- release note -->"
changelog_fixture fenced-only 1 "$changelog_failure" '```markdown' "## $target" '```'
changelog_fixture indented-heading 0 "$pass" "   ## $target"

replace_document_marker() {
    local name="$1"
    local file="$2"
    local pattern="$3"
    local replacement="$4"
    local status="$5"
    local expected="$6"
    local fixture="$scratch/$name"

    new_fixture "$name"
    php -r '
        $contents = file_get_contents($argv[1]);
        $changed = preg_replace($argv[2], $argv[3], $contents, -1, $matches);
        if ($changed === null || $matches !== 1) {
            fwrite(STDERR, "Fixture requires exactly one matching marker in " . $argv[1] . ".\n");
            exit(1);
        }
        file_put_contents($argv[1], $changed);
    ' "$fixture/$file" "$pattern" "$replacement"
    commit_fixture "$fixture"
    assert_gate "$fixture" "$status" "$expected"
}

hide_markup=$'<!--\n$0\n-->'
readme_target_pattern='~^\*\*Release target:\*\*[^\r\n]+$~m'
readme_target_failure='README.md lacks the exact unpublished release target and badges.'
readme_conflict='README.md contains an invalid or conflicting release target declaration.'
replace_document_marker conflicting-release-identity README.md "$readme_target_pattern" \
    $'$0\n\n**Release target:** `1.0.0-rc.4`, a Pre-Stable Release Candidate.' 1 "$readme_conflict"
replace_document_marker conflicting-list-release-identity README.md "$readme_target_pattern" \
    $'$0\n\n1. Package metadata\n   - **Release target:** `1.0.0-rc.4`' 1 "$readme_conflict"
replace_document_marker valid-list-release-identity README.md "$readme_target_pattern" '- $0' 0 "$pass"
replace_document_marker conflicting-table-release-identity README.md "$readme_target_pattern" \
    $'$0\n\n| Package metadata |\n| --- |\n| **Release target:** `1.0.0-rc.4` |' 1 "$readme_conflict"
replace_document_marker valid-alert-release-identity README.md "$readme_target_pattern" \
    $'> [!NOTE]\n> $0' 0 "$pass"
replace_document_marker ignored-list-release-examples README.md "$readme_target_pattern" \
    $'$0\n\n- Template example:\n\n  ```markdown\n  **Release target:** `1.0.0-rc.4`\n  ```\n\n- Quoted example:\n\n  > **Release target:** `1.0.0-rc.4`' 0 "$pass"
readme_install_pattern='#^(```|~~~)bash[^\S\r\n]*\Rcomposer require maatify/php-slug:[^\r\n]+\R\1[^\S\r\n]*$#m'
install_failure='lacks the exact package-scoped installation command for the target and its Persistence dependency.'
replace_document_marker hidden-release-identity README.md "$readme_target_pattern" "$hide_markup" 1 "$readme_target_failure"
replace_document_marker hidden-install-command README.md "$readme_install_pattern" "$hide_markup" 1 "README.md $install_failure"
replace_document_marker hidden-arabic-install-command README_AR.md "$readme_install_pattern" "$hide_markup" 1 "README_AR.md $install_failure"

security_row_pattern='~^(\| `1\.0\.0-rc\.3` pre-release \(artifact target\) \| )No( \|[^\r\n]*)$~m'
security_failure='SECURITY.md must identify the release target and mark it unsupported without conflicting rows.'
replace_document_marker hidden-security-row SECURITY.md "$security_row_pattern" "$hide_markup" 1 "$security_failure"
replace_document_marker styled-security-row SECURITY.md "$security_row_pattern" '${1}**No**${2}' 0 "$pass"
replace_document_marker supported-after-unsupported SECURITY.md "$security_row_pattern" \
    $'$0\n${1}Yes${2}' 1 "$security_failure"
replace_document_marker supported-before-unsupported SECURITY.md "$security_row_pattern" \
    $'${1}Yes${2}\n$0' 1 "$security_failure"
replace_document_marker supported-unrelated-release SECURITY.md "$security_row_pattern" \
    $'$0\n| `1.0.0-rc.30` pre-release (artifact target) | Yes |' 0 "$pass"

new_fixture missing-runtime
printf '%s\n' '/src/Fixture.php export-ignore' >> "$scratch/missing-runtime/.gitattributes"
commit_fixture "$scratch/missing-runtime"
assert_gate "$scratch/missing-runtime" 1 'Missing: [src/Fixture.php].'

new_fixture composer-only-missing-runtime
php -r '
    $manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
    $manifest["archive"]["exclude"][] = "/src/Fixture.php";
    file_put_contents($argv[1], json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
' "$scratch/composer-only-missing-runtime/composer.json"
commit_fixture "$scratch/composer-only-missing-runtime"
assert_gate "$scratch/composer-only-missing-runtime" 1 'COMPOSER_ZIP payload differs from the exact candidate tree. Missing: [src/Fixture.php].'

new_fixture forbidden-development-path
printf '%s\n' '/.env -export-ignore' >> "$scratch/forbidden-development-path/.gitattributes"
printf '%s\n' 'SYNTHETIC_FORBIDDEN_ARCHIVE_FIXTURE' > "$scratch/forbidden-development-path/.env"
git -C "$scratch/forbidden-development-path" add -f .env
commit_fixture "$scratch/forbidden-development-path"
assert_gate "$scratch/forbidden-development-path" 1 'Forbidden archive path: .env'

printf 'Release Artifact Verification active regression passed (%d cases).\n' "$gate_cases"
