#!/usr/bin/env bash

set -euo pipefail

unset CDPATH
if [[ -n "${TMPDIR:-}" ]]; then
    if [[ "$TMPDIR" != /* ]]; then
        TMPDIR="./$TMPDIR"
    fi
    TMPDIR="$(cd -- "$TMPDIR" && pwd -P)"
    export TMPDIR
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$repo_root"

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Required command is unavailable: $1" >&2
        exit 1
    fi
}

composer_policy() {
    require_command php
    php tools/ci/verify-composer-policy.php
    php tools/ci/test-composer-policy.php
}

php_runtime() {
    require_command php
    php tools/ci/verify-php-runtime.php "$1"
}

latest_dependencies() {
    require_command composer
    composer_policy
    composer validate --strict
    composer update --no-interaction --prefer-dist --no-progress
    composer dump-autoload --optimize --strict-psr
    composer check-platform-reqs
    composer check:php-constraint
}

lowest_dependencies() {
    require_command composer
    composer_policy
    composer validate --strict
    composer update --prefer-lowest --prefer-stable --no-interaction --prefer-dist --no-progress
    composer dump-autoload --optimize --strict-psr
    composer check-platform-reqs
    composer check:php-constraint
    composer audit --no-interaction --abandoned=fail
}

syntax() {
    require_command php
    local shell_files=()
    while IFS= read -r -d '' file; do
        shell_files+=("$file")
    done < <(find tools/ci -type f -name '*.sh' -print0)
    if ((${#shell_files[@]} == 0)); then
        echo 'No package-owned shell scripts were found for syntax verification.' >&2
        exit 1
    fi
    for file in "${shell_files[@]}"; do
        bash -n "$file"
    done
    local files=()
    while IFS= read -r -d '' file; do
        files+=("$file")
    done < <(find src tests tools examples -type f -name '*.php' -print0)
    if ((${#files[@]} == 0)); then
        echo 'No package-owned PHP files were found for syntax verification.' >&2
        exit 1
    fi
    for file in "${files[@]}"; do
        php -l "$file" >/dev/null
    done
    if [[ -f .php-cs-fixer.php ]]; then
        php -l .php-cs-fixer.php >/dev/null
    fi
}

phpstan() {
    require_command php
    if [[ ! -x vendor/bin/phpstan ]]; then
        echo 'PHPStan is unavailable; run a dependency gate first.' >&2
        exit 1
    fi
    vendor/bin/phpstan analyse --memory-limit=512M
}

test_unit() {
    require_command php
    if [[ ! -x vendor/bin/phpunit ]]; then
        echo 'PHPUnit is unavailable; run a dependency gate first.' >&2
        exit 1
    fi
    vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite unit --do-not-cache-result
}

style() {
    if [[ ! -x vendor/bin/php-cs-fixer ]]; then
        echo 'PHP CS Fixer is unavailable; run a dependency gate first.' >&2
        exit 1
    fi
    vendor/bin/php-cs-fixer fix --dry-run --diff --allow-risky=yes --sequential --using-cache=no
    php tools/ci/test-per-cs31.php
    php tools/ci/verify-per-cs31.php
}

audit() {
    require_command composer
    composer_policy
    composer audit --no-interaction --abandoned=fail
}

schema_contract() {
    local asset
    for asset in 001_slug_rc1.sql 002_operational_reporting_indexes.sql 003_registry_lock_projection.sql; do
        if [[ ! -f "schema/mysql/$asset" ]]; then
            echo "The ordered package schema asset is missing: $asset" >&2
            exit 1
        fi
    done
}

workflow_lint() {
    local workflow_files=()
    while IFS= read -r file; do
        workflow_files+=("$file")
    done < <(find .github/workflows -type f \( -name '*.yml' -o -name '*.yaml' \) -print | sort)
    if ((${#workflow_files[@]} == 0)); then
        echo 'No workflow files were found for workflow lint.' >&2
        exit 1
    fi
    require_command docker
    docker run --rm --network none -v "$repo_root:/repo:ro" -w /repo docker.io/rhysd/actionlint@sha256:b1934ee5f1c509618f2508e6eb47ee0d3520686341fec936f3b79331f9315667 "${workflow_files[@]}"
}

whitespace() {
    local comparison_range="${1:-}"
    if [[ "$comparison_range" != *...* ]]; then
        echo 'Whitespace verification requires a committed comparison range such as BASE_SHA...HEAD_SHA.' >&2
        exit 1
    fi
    local base_ref="${comparison_range%%...*}"
    local head_ref="${comparison_range##*...}"
    git rev-parse --verify "$base_ref^{commit}" >/dev/null
    git rev-parse --verify "$head_ref^{commit}" >/dev/null
    git diff --check "$base_ref...$head_ref"
}

latest_quality() {
    latest_dependencies
    syntax
    phpstan
    style
    audit
}

latest_tests() {
    latest_dependencies
    test
}

lowest_tests() {
    lowest_dependencies
    test
}

test_integration() {
    bash tools/ci/run-integration.sh integration
}

test_system() {
    bash tools/ci/run-integration.sh system
}

test() {
    test_unit
    bash tools/ci/run-integration.sh full
}

consumer() {
    require_command php
    require_command composer
    composer_policy
    bash tools/ci/run-integration.sh consumer
}

examples_smoke() {
    latest_dependencies
    php examples/canonicalization.php
    php examples/custom-profile.php
    bash tools/ci/run-integration.sh examples
}

runtime_portability() {
    latest_dependencies
    require_command php
    echo "PHP_VERSION=$(php -r 'echo PHP_VERSION;')"
    echo "INTL_ICU_VERSION=$(php -r 'echo defined("INTL_ICU_VERSION") ? INTL_ICU_VERSION : "UNAVAILABLE";')"
    php -r '
        if (class_exists(IntlChar::class)) {
            $version = IntlChar::getUnicodeVersion();
            echo "UNICODE_VERSION=" . implode(".", $version) . PHP_EOL;
        } else {
            echo "UNICODE_VERSION=UNAVAILABLE" . PHP_EOL;
        }
    '
    vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result \
        tests/Unit/Profile/RuntimeCompatibilityTest.php \
        tests/Unit/Canonicalization/ProfileCanonicalizationTest.php \
        tests/Unit/Canonicalization/UnicodeNfcClosureTest.php \
        tests/Unit/Validation/ProfileInputValidationTest.php
    php examples/canonicalization.php
}

usage() {
    cat >&2 <<'USAGE'
Usage: tools/ci/run-gate.sh <gate> [arguments...]

Gates:
  latest-quality   Latest dependency resolution, syntax, PHPStan max, style, audit
  latest-tests     Latest dependency resolution, then the complete maintained suite
  lowest-tests     Lowest dependency resolution on PHP 8.4, then the complete maintained suite
  test             Unit, Integration, and System suites
  test-unit        Unit suite only; Docker is not required
  test-integration Integration suite through the canonical Compose lifecycle
  test-system      System suite through the canonical Compose lifecycle
  mariadb-snapshot PDO and snapshot regressions on MariaDB through Compose
  syntax           PHP syntax for src/, tests/, tools/, examples/, and PHP tool configuration
  phpstan          PHPStan max using phpstan.neon
  php-runtime MINOR Verify the actual Stable PHP interpreter and patch floor for MINOR
  style            Composite PER-CS 3.1 verification (PHP CS Fixer + supplemental verifier)
  audit            Composer security and abandoned-package audit
  schema           Verify the package-owned schema path exists
  workflow-lint    actionlint for every .github/workflows/*.yml|*.yaml
  whitespace RANGE Git-aware whitespace check for an explicit BASE...HEAD committed range
  consumer         Consumer Verification Harness clean run x2
  release-artifact SHA TARGET Verify archives for an exact x.y.z-rc.N target
  release-artifact SHA ""     Verify there is no active release target
  examples-smoke   All maintained standalone examples; persisted examples use the canonical Compose lifecycle
  runtime-portability Latest dependencies, runtime diagnostics, focused portability tests, and stateless example
USAGE
    exit 2
}

gate="${1:-}"
case "$gate" in
    latest-quality) latest_quality ;;
    latest-tests) latest_tests ;;
    lowest-tests) lowest_tests ;;
    test) test ;;
    test-unit) test_unit ;;
    test-integration) test_integration ;;
    test-system) test_system ;;
    mariadb-snapshot) bash tools/ci/run-integration.sh mariadb-snapshot ;;
    syntax) syntax ;;
    phpstan) phpstan ;;
    php-runtime) php_runtime "${2:-}" ;;
    style) style ;;
    audit) audit ;;
    schema) schema_contract ;;
    workflow-lint) workflow_lint ;;
    whitespace) whitespace "${2:-}" ;;
    consumer) consumer ;;
    release-artifact) shift; bash tools/ci/verify-release-artifact.sh "$@" ;;
    examples-smoke) examples_smoke ;;
    runtime-portability) runtime_portability ;;
    *) usage ;;
esac
