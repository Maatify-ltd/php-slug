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

compose_file="$repo_root/compose.integration.yml"
mode="${1:-}"

if [[ $# -ne 1 || ! "$mode" =~ ^(integration|system|full|consumer|examples|mariadb-snapshot)$ ]]; then
    echo 'Usage: tools/ci/run-integration.sh {integration|system|full|consumer|examples|mariadb-snapshot}' >&2
    exit 2
fi

run_id="${GITHUB_RUN_ID:-local}"
run_attempt="${GITHUB_RUN_ATTEMPT:-1}"
project_name="maatify-slug-${mode}-${run_id}-${run_attempt}-$$"
project_name="$(printf '%s' "$project_name" | tr '[:upper:]' '[:lower:]' | tr -cd 'a-z0-9-' | cut -c1-63)"
if [[ -z "$project_name" ]]; then
    echo 'Unable to create a valid unique Compose project identity.' >&2
    exit 1
fi

compose=(docker compose --project-name "$project_name" --file "$compose_file")
service=mysql
if [[ "$mode" == mariadb-snapshot ]]; then
    service=mariadb
    compose+=(--profile mariadb)
fi
started=0
teardown_status=0

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        echo "Required command is unavailable: $1" >&2
        exit 1
    fi
}

diagnostics() {
    echo 'Compose status before teardown:' >&2
    if ! "${compose[@]}" ps; then
        echo 'Unable to read Compose status.' >&2
    fi
    echo "$service container logs before teardown:" >&2
    if ! "${compose[@]}" logs --no-color "$service"; then
        echo "Unable to read $service container logs." >&2
    fi
}

cleanup() {
    local original_status=$?

    if ((original_status != 0 && started == 1)); then
        diagnostics
    fi

    if ((started == 1)); then
        if ! "${compose[@]}" down --volumes --remove-orphans; then
            echo 'Compose teardown failed.' >&2
            teardown_status=1
        fi
        local remaining
        if remaining=$("${compose[@]}" ps -aq); then
            if [[ -n "$remaining" ]]; then
                echo "Disposable Compose containers remain after teardown: $remaining" >&2
                teardown_status=1
            fi
        else
            echo 'Unable to verify disposable Compose teardown.' >&2
            teardown_status=1
        fi
    fi

    if ((original_status == 0 && teardown_status != 0)); then
        exit "$teardown_status"
    fi
    exit "$original_status"
}

trap cleanup EXIT

require_command docker
require_command php
if ! docker compose version >/dev/null 2>&1; then
    echo 'Docker Compose v2 is unavailable.' >&2
    exit 1
fi
if [[ ! -f "$compose_file" ]]; then
    echo "Canonical Compose file is missing: $compose_file" >&2
    exit 1
fi
if ! php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);'; then
    echo 'The pdo_mysql extension is required for Integration infrastructure.' >&2
    exit 1
fi
if [[ "$mode" =~ ^(integration|system|full|mariadb-snapshot)$ && ! -x vendor/bin/phpunit ]]; then
    echo 'PHPUnit is unavailable; run a dependency gate first.' >&2
    exit 1
fi
if [[ "$mode" == consumer ]] && ! command -v composer >/dev/null 2>&1; then
    echo 'Required command is unavailable: composer' >&2
    exit 1
fi

"${compose[@]}" config --quiet

export SLUG_TEST_DB_HOST=127.0.0.1
export SLUG_TEST_DB_NAME=maatify_slug_test
export SLUG_TEST_DB_USER=slug_test
export SLUG_TEST_DB_PASSWORD=slug_test_password
export SLUG_TEST_DB_READER_USER=slug_readonly_test
export SLUG_TEST_DB_READER_PASSWORD=slug_readonly_test_password

umask 077
started=1
"${compose[@]}" up --detach --force-recreate --renew-anon-volumes "$service"

database_port=''
for attempt in $(seq 1 60); do
    endpoint=''
    if endpoint=$("${compose[@]}" port "$service" 3306 2>/dev/null); then
        endpoint="${endpoint##*$'\n'}"
        candidate_port="${endpoint##*:}"
        if [[ "$candidate_port" =~ ^[0-9]+$ ]]; then
            database_port="$candidate_port"
            export SLUG_TEST_DB_PORT="$database_port"
            break
        fi
    fi
    if ((attempt < 60)); then
        sleep 2
    fi
done
if [[ -z "$database_port" ]]; then
    echo "Unable to discover the dynamic $service loopback port." >&2
    exit 1
fi

probe_database() {
    php -r '
        $pdo = new PDO(
            sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("SLUG_TEST_DB_HOST"), getenv("SLUG_TEST_DB_PORT"), getenv("SLUG_TEST_DB_NAME")),
            getenv("SLUG_TEST_DB_USER"),
            getenv("SLUG_TEST_DB_PASSWORD"),
            [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->query("SELECT 1");
    ' >/dev/null 2>&1
}

database_ready=0
for attempt in $(seq 1 60); do
    if probe_database; then
        echo "$service integration target is ready after attempt $attempt."
        database_ready=1
        break
    fi
    if ((attempt < 60)); then
        sleep 2
    fi
done
if ((database_ready == 0)); then
    echo "$service integration target did not become ready within 120 seconds." >&2
    exit 1
fi

assert_no_schema_residue() {
    php -r '
        $pdo = new PDO(
            sprintf("mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4", getenv("SLUG_TEST_DB_HOST"), getenv("SLUG_TEST_DB_PORT"), getenv("SLUG_TEST_DB_NAME")),
            getenv("SLUG_TEST_DB_USER"),
            getenv("SLUG_TEST_DB_PASSWORD"),
            [PDO::ATTR_TIMEOUT => 2, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $count = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 0x6d61615f736c75675f25")->fetchColumn();
        if ($count !== 0) {
            fwrite(STDERR, "Package schema residue detected: {$count} table(s).\n");
            exit(1);
        }
    '
}

assert_no_schema_residue

run_phpunit_suite() {
    vendor/bin/phpunit --configuration phpunit.xml.dist --testsuite "$1" --do-not-cache-result
}

run_workflow() {
    case "$mode" in
        integration)
            run_phpunit_suite integration
            ;;
        system)
            run_phpunit_suite system
            ;;
        full)
            run_phpunit_suite integration || return $?
            run_phpunit_suite system
            ;;
        mariadb-snapshot)
            SLUG_TEST_REQUIRE_SNAPSHOT_ISOLATION=1 vendor/bin/phpunit --configuration phpunit.xml.dist --do-not-cache-result \
                tests/System/Lifecycle/HostSnapshotMutationSystemTest.php \
                tests/System/Resolution/AvailabilityObservationSystemTest.php \
                tests/System/Resolution/ReadCapabilitySystemTest.php
            ;;
        consumer)
            php tests/Consumer/run.php
            ;;
        examples)
            php examples/persisted-lifecycle.php
            ;;
    esac
}

run_status=0
run_workflow || run_status=$?
residue_status=0
assert_no_schema_residue || residue_status=1

if ((run_status != 0)); then
    exit "$run_status"
fi
if ((residue_status != 0)); then
    exit 1
fi
