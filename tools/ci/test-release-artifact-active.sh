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
cp "$repo_root/tools/ci/"{run-gate.sh,verify-release-artifact.sh,verify-release-artifact.php,release-artifact-markdown.php,release-artifact-files.php} \
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

[![Artifact target](https://img.shields.io/badge/Artifact%20target-1.0.0--rc.3-blue.svg)](#publication-state)
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

## حالة النشر

**الإصدار المستهدف:** `1.0.0-rc.3`، نسخة Release Candidate قبل الإصدار المستقر.

حالة النشر عند التأهيل: `1.0.0-rc.3` غير منشورة.

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

# Simulate Composer metadata over the archived fixture without querying Packagist.
published_project="$scratch/published"
published_package="$published_project/vendor/maatify/php-slug"
mkdir -p "$published_package" "$published_project/vendor/composer"
published_sha="$(git -C "$base" rev-parse HEAD)"
git -C "$base" archive "$published_sha" | tar -x -C "$published_package"
git -C "$base" ls-tree -rz "$published_sha" > "$scratch/published-tree"
php "$repo_root/tools/ci/verify-published-artifact.php" prepare "$target" "$base/composer.json" > "$published_project/composer.json"
grep -Fq '"maatify/php-persistence":"1.0.0-rc.1@RC"' "$published_project/composer.json"

assert_published_fixture() {
    local mode="$1"
    local expected_status="$2"
    local expected="$3"
    local output status=0
    cat > "$published_project/vendor/composer/installed.json" <<JSON
{"packages":[{"name":"maatify/php-slug","version":"v$target","installation-source":"$mode","install-path":"../maatify/php-slug","dist":{"type":"zip","url":"https://example.invalid/archive.zip","reference":"$published_sha"},"source":{"reference":"$published_sha"}}]}
JSON
    output=$(php "$repo_root/tools/ci/verify-published-artifact.php" verify "$target" "$published_sha" "$scratch/published-tree" "$published_project" 2>&1) || status=$?
    if [[ "$status" != "$expected_status" ]] || ! grep -Fq -- "$expected" <<< "$output"; then
        printf 'Published artifact fixture failed (expected exit %s and %s, got %s).\n%s\n' "$expected_status" "$expected" "$status" "$output" >&2
        exit 1
    fi
}

assert_published_fixture dist 0 'PAV=PASS'
assert_published_fixture source 1 'Installed Zip dist identity or Git reference does not match the qualified release.'
rm -- "$published_package/src/Fixture.php"
assert_published_fixture dist 1 'Required regular file missing in dist: src/Fixture.php'
printf '%s\n' 'Published artifact regression fixtures passed (3 cases).'
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
publication_failure='must have exactly one level-2 publication-state section and one matching release target declaration in a direct paragraph.'
installation_failure='must have exactly one level-2 installation section with one direct Bash fence containing only the canonical installation command.'
readme_target_pattern='~^\*\*Release target:\*\*[^\r\n]+$~m'
readme_install_pattern='#^(```|~~~)bash[^\S\r\n]*\Rcomposer require maatify/php-slug:[^\r\n]+\R\1[^\S\r\n]*$#m'
install_line_pattern='~^composer require maatify/php-slug:[^\r\n]+$~m'
canonical_install="composer require maatify/php-slug:$target@RC maatify/php-persistence:1.0.0-rc.1@RC"
stale_install='composer require maatify/php-slug:1.0.0-rc.2@RC maatify/php-persistence:1.0.0-rc.1@RC'
install_before="$stale_install"$'\n$0'
install_after=$'$0\n'"$stale_install"

replace_document_marker conflicting-release-identity README.md "$readme_target_pattern" \
    $'$0\n\n**Release target:** `1.0.0-rc.4`, a Pre-Stable Release Candidate.' 1 "README.md $publication_failure"
replace_document_marker conflicting-list-release-identity README.md "$readme_target_pattern" \
    $'$0\n\n1. Package metadata\n   - **Release target:** `1.0.0-rc.4`' 1 "README.md $publication_failure"
replace_document_marker list-only-release-identity README.md "$readme_target_pattern" '- $0' 1 "README.md $publication_failure"
replace_document_marker conflicting-table-release-identity README.md "$readme_target_pattern" \
    $'$0\n\n| Package metadata |\n| --- |\n| **Release target:** `1.0.0-rc.4` |' 1 "README.md $publication_failure"
replace_document_marker alert-only-release-identity README.md "$readme_target_pattern" \
    $'> [!NOTE]\n> $0' 1 "README.md $publication_failure"
replace_document_marker conflicting-publication-quote README.md "$readme_target_pattern" \
    $'$0\n\n> **Release target:** `1.0.0-rc.4`' 1 "README.md $publication_failure"
replace_document_marker quote-only-release-identity README.md "$readme_target_pattern" '> $0' 1 "README.md $publication_failure"
replace_document_marker conflicting-publication-raw-html README.md "$readme_target_pattern" \
    $'$0\n\n<p><strong>Release target:</strong> <code>1.0.0-rc.4</code></p>' 1 \
    'README.md publication-state section must use Markdown. Raw HTML blocks are not allowed.'
replace_document_marker ignored-publication-comment-example README.md "$readme_target_pattern" \
    $'$0\n\n<!--\n<p><strong>Release target:</strong> <code>1.0.0-rc.4</code></p>\n-->' 0 "$pass"
replace_document_marker ignored-publication-fenced-example README.md "$readme_target_pattern" \
    $'$0\n\n```markdown\n**Release target:** `1.0.0-rc.4`\n```' 0 "$pass"
replace_document_marker ignored-history-quote-example README.md "$readme_install_pattern" \
    $'$0\n\n## Earlier release examples\n\n> **Release target:** `1.0.0-rc.2`' 0 "$pass"
replace_document_marker ignored-history-list-examples README.md "$readme_install_pattern" \
    $'$0\n\n## Earlier release examples\n\n- Template example:\n\n  ```markdown\n  **Release target:** `1.0.0-rc.2`\n  ```\n\n- Quoted example:\n\n  > **Release target:** `1.0.0-rc.2`' 0 "$pass"
replace_document_marker missing-artifact-target-badge README.md '~^\[!\[Artifact target\][^\r\n]+$~m' '' 1 \
    'README.md must show the exact release target in its Artifact target and Install after publication badges.'

assert_readme_structure() {
    local language="$1"
    local file="$2"
    local publication="$3"
    local label="$4"
    local installation="$5"
    local publication_pattern="~^## $publication\$~m"
    local target_pattern="~^\*\*$label:\*\*[^\r\n]+\$~m"
    local installation_pattern="~^## $installation\$~m"

    replace_document_marker "$language-missing-publication-heading" "$file" "$publication_pattern" '' 1 "$file $publication_failure"
    replace_document_marker "$language-wrong-publication-heading-level" "$file" "$publication_pattern" '#$0' 1 "$file $publication_failure"
    replace_document_marker "$language-duplicate-publication-heading" "$file" "$publication_pattern" $'$0\n\n$0' 1 "$file $publication_failure"
    replace_document_marker "$language-hidden-release-identity" "$file" "$target_pattern" "$hide_markup" 1 "$file $publication_failure"
    replace_document_marker "$language-malformed-release-label" "$file" "$target_pattern" "**$label** \`$target\`" 1 "$file $publication_failure"
    replace_document_marker "$language-stale-release-label" "$file" "$target_pattern" "**$label:** \`1.0.0-rc.2\`" 1 "$file $publication_failure"
    replace_document_marker "$language-duplicate-release-label" "$file" "$target_pattern" $'$0\n\n$0' 1 "$file $publication_failure"
    replace_document_marker "$language-missing-installation-heading" "$file" "$installation_pattern" '' 1 "$file $installation_failure"
    replace_document_marker "$language-wrong-installation-heading-level" "$file" "$installation_pattern" '#$0' 1 "$file $installation_failure"
    replace_document_marker "$language-duplicate-installation-heading" "$file" "$installation_pattern" $'$0\n\n$0' 1 "$file $installation_failure"
    replace_document_marker "$language-hidden-install-command" "$file" "$readme_install_pattern" "$hide_markup" 1 "$file $installation_failure"
    replace_document_marker "$language-wrong-fence-language" "$file" "$readme_install_pattern" $'```sh\n'"$canonical_install"$'\n```' 1 "$file $installation_failure"
    replace_document_marker "$language-duplicate-install-fence" "$file" "$readme_install_pattern" $'$0\n\n$0' 1 "$file $installation_failure"
    replace_document_marker "$language-stale-install-before" "$file" "$install_line_pattern" "$install_before" 1 "$file $installation_failure"
    replace_document_marker "$language-stale-install-after" "$file" "$install_line_pattern" "$install_after" 1 "$file $installation_failure"
}

assert_readme_structure en README.md 'Publication State' 'Release target' Installation
assert_readme_structure ar README_AR.md 'حالة النشر' 'الإصدار المستهدف' 'التثبيت'
replace_document_marker ar-uppercase-release-version README_AR.md '~^\*\*الإصدار المستهدف:\*\*[^\r\n]+$~m' \
    '**الإصدار المستهدف:** `1.0.0-RC.3`' 1 "README_AR.md $publication_failure"

replace_document_marker stale-install-separate-block README.md "$readme_install_pattern" \
    $'$0\n\n```bash\n'"$stale_install"$'\n```' 1 "README.md $installation_failure"
replace_document_marker stale-sh-install-separate-block README.md "$readme_install_pattern" \
    $'$0\n\n```sh\n'"$stale_install"$'\n```' 1 "README.md $installation_failure"
replace_document_marker environment-prefixed-stale-install README.md "$install_line_pattern" \
    $'$0\nenv COMPOSER_NO_INTERACTION=1 '"$stale_install" 1 "README.md $installation_failure"
replace_document_marker unexplained-extra-command README.md "$install_line_pattern" \
    $'$0\nphp -v' 1 "README.md $installation_failure"
replace_document_marker install-block-comment README.md "$install_line_pattern" \
    $'# Example comment\n$0' 1 "README.md $installation_failure"
replace_document_marker nested-only-install-fence README.md "$readme_install_pattern" \
    $'- Example:\n\n  ```bash\n  '"$canonical_install"$'\n  ```' 1 "README.md $installation_failure"
replace_document_marker nested-extra-fence README.md "$readme_install_pattern" \
    $'$0\n\n- Example:\n\n  ```sh\n  '"$stale_install"$'\n  ```' 1 "README.md $installation_failure"
replace_document_marker extra-html-install-block README.md "$readme_install_pattern" \
    $'$0\n\n<pre><code>'"$stale_install"$'</code></pre>' 1 "README.md $installation_failure"
replace_document_marker blank-install-lines README.md "$install_line_pattern" $'\n$0\n' 0 "$pass"
replace_document_marker historical-install-outside-section README.md "$readme_install_pattern" \
    $'$0\n\n## Earlier release examples\n\n```bash\n'"$stale_install"$'\n```' 0 "$pass"
replace_document_marker historical-arabic-install-outside-section README_AR.md "$readme_install_pattern" \
    $'$0\n\n## أمثلة الإصدارات السابقة\n\n~~~bash\n'"$stale_install"$'\n~~~' 0 "$pass"

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

composer_version_fixture() {
    local name="$1"
    local version_json="$2"
    local fixture="$scratch/$name"

    new_fixture "$name"
    php -r '
        $manifest = json_decode(file_get_contents($argv[1]), true, flags: JSON_THROW_ON_ERROR);
        $manifest["version"] = json_decode($argv[2], flags: JSON_THROW_ON_ERROR);
        file_put_contents($argv[1], json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
    ' "$fixture/composer.json" "$version_json"
    commit_fixture "$fixture"
    assert_gate "$fixture" 1 'Archived composer.json must not declare a version key.'
}

composer_version_fixture explicit-composer-version '"1.0.0-rc.2"'
composer_version_fixture explicit-target-composer-version "\"$target\""

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
