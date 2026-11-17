# Contributing to maatify/php-slug

Welcome to the Maatify Slug package. Thank you for your interest in contributing to the project!

## Package Identity and Boundaries

This is a standalone, reusable PHP/Composer library that provides a deterministic Slug Lifecycle Engine. It manages slug generation, exact claiming, generated allocation, resolution, and history persistence over MySQL, but it intentionally does *not* manage:

- HTTP Request/Response handling.
- URL Routing.
- SEO logic (e.g. 301 redirects, canonical meta tags).

Any contribution must strictly respect these boundaries. Architectural changes should be discussed before implementation.

## Types of Contributions

1. **Bug Fixes:** Please provide a reproducible test case.
2. **Feature Changes:** Architectural changes and large additions should be discussed before implementation to ensure alignment with the current canonical package contract and applicable architecture and Standards, without creating or duplicating a competing authority.
3. **Documentation:** Typo fixes and conceptual clarifications are welcome.
4. **Security Vulnerabilities:** Do not use GitHub Issues or PRs. Refer to [SECURITY.md](SECURITY.md) to report security flaws privately via `support@maatify.dev`.

## PR and Architecture Expectations

- Tests must be included for bug fixes and new features.
- Ensure that integration tests execute against the MySQL environment.
- Do not commit changes to `composer.lock`; this is a reusable library, and `composer.lock` is ignored.

## Prerequisites for Local Development

Use a [repository checkout](https://github.com/Maatify-ltd/php-slug) for this setup and the commands below. The distribution archive ships the runtime, schema, and consumer examples. The [CI tooling](https://github.com/Maatify-ltd/php-slug/tree/main/tools/ci) and disposable database configuration are available in the checkout.

- **PHP (Composer):** `>=8.4.27 <8.5.0 || >=8.5.12 <9.0.0`
- **Runtime extensions:** `filter`, `intl`, `mbstring`, `pdo`, `pdo_mysql`
- **Development extensions:** `pdo_sqlite` for the Factory unit test and `iconv` for the Composer development toolchain.
- **Docker Engine:** Required for Integration, System, complete-suite, Consumer Harness, and examples-smoke gates.
- **Docker Compose v2:** Required for the repository-owned Integration lifecycle.

CI verifies PHP 8.4 and 8.5. Composer also admits newer PHP 8 minors within this range. Each newer minor needs its own security and compatibility evidence before it can be listed as supported.

The PHP runtime remains on the host or CI runner. The canonical disposable database uses
`mysql:8.0.36`, binds a dynamic port on loopback, and uses the temporary database
`maatify_slug_test` with disposable non-production credentials. The orchestration starts the
isolated Compose service, discovers the port, exports the Integration-scoped process environment,
runs the requested verification, and tears everything down. Developers must not provide an
external MySQL service or export `SLUG_TEST_DB_*` variables manually.

## Running Tests and Quality Gates

Run the applicable gates from the repository root before submitting a pull request. Install the Composer development dependencies to run the Composer checks.

### 1. Static Analysis & Coding Standards

```bash
bash tools/ci/run-gate.sh latest-quality
```
This runs PHPStan and PHP-CS-Fixer.

Check the actual PHP interpreter for the PHP 8.4 lane and the declared Composer constraint boundaries:

```bash
bash tools/ci/run-gate.sh php-runtime 8.4
composer check:php-constraint
```

Use `php-runtime 8.5` for the PHP 8.5 lane.

### 2. Run Test Suites

Run Unit tests only; Docker is not required:
```bash
composer test:unit
```

Run the focused Integration suite through the canonical Compose lifecycle:
```bash
composer test:integration
```

Run focused System evidence through the same lifecycle:
```bash
bash tools/ci/run-gate.sh test-system
```

Run the PDO and Host snapshot regression suite against disposable MariaDB:

```bash
bash tools/ci/run-gate.sh mariadb-snapshot
```

Run the complete maintained suite (Unit + Integration + System):
```bash
composer test
```

Resolve latest dependencies and run the complete suite:
```bash
bash tools/ci/run-gate.sh latest-tests
```

Resolve lowest dependencies and run the complete suite:
```bash
bash tools/ci/run-gate.sh lowest-tests
```

### 3. Verification Harness

Verify consumer integration by running the consumer harness from clean external Composer roots:
```bash
bash tools/ci/run-gate.sh consumer
```

The Consumer Verification Harness uses the same canonical Compose definition and lifecycle as
the Integration and System suites.

### 4. Examples smoke verification

Run all maintained standalone examples, with persisted examples using the canonical Compose lifecycle:
```bash
bash tools/ci/run-gate.sh examples-smoke
```

The persisted example uses the same `compose.integration.yml`, isolated project, dynamic loopback
port, readiness probe, process environment, diagnostics, and teardown as the other Integration
boundaries. Its CLI requires a disposable database with a name ending in `_test` and
refuses a database with any pre-existing Slug tables or views.

### 5. Workflow Linting and Diff Checks

Lint GitHub workflows:
```bash
bash tools/ci/run-gate.sh workflow-lint
```
Check for trailing whitespace or formatting errors introduced in your PR:
```bash
bash tools/ci/run-gate.sh whitespace BASE...HEAD
```
Replace `BASE...HEAD` with your specific base and head branches (e.g., `main...HEAD`).

### 6. Release Artifact Verification

Run the same command as CI from a clean repository checkout. Both modes require Bash, Git, PHP with DOM, and [cmark-gfm 0.29.0.gfm.13](https://github.com/github/cmark-gfm/releases/tag/0.29.0.gfm.13) to parse release metadata. The CI installer checks the pinned source archive and builds the tool into a local directory. To use it locally, have `curl`, `shasum`, `tar`, CMake, a C/C++ compiler, and a build tool such as Make available.

```bash
markdown_tools="$(mktemp -d)"
bash tools/ci/install-release-markdown.sh "$markdown_tools"
export PATH="$markdown_tools/bin:$PATH"
```

Both modes take the full 40-character checkout SHA and an explicit target argument. For an active release, pass the exact `x.y.z-rc.N` target without a leading `v`. This mode also needs Composer, PHP Phar support, and either the PHP zip extension or the `zip` command. The command inspects the Git and Composer archives and prints the SHA, target, digests, file counts, and result.

```bash
bash tools/ci/run-gate.sh release-artifact "$(git rev-parse HEAD)" 1.0.0-rc.3
```

When no release target is active, pass an explicit empty argument to check the committed release metadata.

```bash
bash tools/ci/run-gate.sh release-artifact "$(git rev-parse HEAD)" ""
```

In the [CI workflow](https://github.com/Maatify-ltd/php-slug/blob/main/.github/workflows/ci.yml), set `RELEASE_ARTIFACT_TARGET` to the exact RC target during preparation. For this target, use the following structure:

| File | Level-2 publication section | Level-2 installation section |
|---|---|---|
| `README.md` | `Publication State` | `Installation` |
| `README_AR.md` | `حالة النشر` | `التثبيت` |

Use each heading once in its file. In the publication section, start one top-level paragraph with the localized `Release target:` or `الإصدار المستهدف:` declaration followed by the exact active target in plain text or inline code. The release-target declaration is reserved for the current artifact.

In the installation section, use one direct Bash fence with one nonblank line containing the canonical exact Composer command for Slug and its Persistence RC dependency. Whitespace is allowed, and the fence is reserved for that command. Put all other code blocks and historical examples outside the two reserved sections. Keep raw HTML blocks outside both reserved sections. HTML comments may remain in either section.

Clear `RELEASE_ARTIFACT_TARGET` to `''` when no RC is in preparation. The inactive check reads both READMEs and the CHANGELOG from the candidate commit. It requires readable, nonempty metadata and fails if any of the three files signals an active RC, including a quoted release declaration inside a reserved publication section. After publication, update both READMEs to show the published state and record the actual publication date in the CHANGELOG.

CI uses Stable PHP 8.5 and Composer 2.10 and always runs both regression suites. The active suite needs the archive prerequisites. To run the same suites locally:

```bash
bash tools/ci/test-release-artifact-inactive.sh
bash tools/ci/test-release-artifact-active.sh
```

For release qualification, run full applicable CI and archive verification on the same candidate commit on `main`. The automated result covers archive SHA and file contents, the Composer manifest, exact release identities and install commands, and the structural CHANGELOG and SECURITY checks.

Review the archived documents on that same SHA and record the semantic result alongside the CI evidence before qualification. Confirm that the publication and availability claims, historical guidance, CHANGELOG allocation and date, and SECURITY support wording match the actual release state in context. Follow [CI Workflow Standard §2.5](https://github.com/Maatify-ltd/php-engineering-standards/blob/7f73468922d4e58b5d86cd9511e1081c28acf420/standards/packages/CI_WORKFLOW_STANDARD.md#25-release-artifact-verification). Repeat verification if the candidate content changes. Tag, Release, and Publish remain subject to owner authorization after qualification.

### Published Artifact Verification

Run this from a checkout after publication. Replace the tag and reference below with the exact published tag and its independently recorded full qualification SHA. This needs PHP with DOM, Composer, Git, `cmark-gfm`, and direct network access to Packagist and the reported distribution host.

```bash
tag=v1.0.0-rc.3
reference='<full qualified Git SHA recorded in the release PR>'
git fetch origin tag "$tag"
bash tools/ci/verify-published-artifact.sh "${tag#v}" "$reference"
```

The command isolates the external Composer project, home, cache and environment, sets `minimum-stability` to `stable` and grants RC allowance only to the exact Slug version and its pinned Persistence RC when applicable. It verifies the installed delivery mode and reference, the required Git files and their bytes, and release presentation in the installed directory. A zero exit verifies the installed bytes and structural checks and reports `PAV=SEMANTIC_REVIEW_REQUIRED`. Review the release claims in the README, package reference, CHANGELOG and SECURITY at the verified Git reference. The command has already proved that those files exactly match the installed bytes. Record the combined Published Artifact Verification result and retain the command output and semantic review with the release evidence. Use the existing Consumer Verification Harness for independent runtime evidence. See [CI Workflow Standard §2.6](https://github.com/Maatify-ltd/php-engineering-standards/blob/7f73468922d4e58b5d86cd9511e1081c28acf420/standards/packages/CI_WORKFLOW_STANDARD.md#26-published-artifact-verification).

Thank you for contributing to the stability and reliability of the Maatify Ecosystem!

## Read-only and Shell Path Regressions

The canonical Compose lifecycle provisions a disposable SELECT-only account from
`tests/Fixtures/Infrastructure/readonly-account.sql` for both database services.
`run-integration.sh` supplies its credentials to the query tests. These tests fail
if the fixture is missing; they do not silently skip reader verification.
The MariaDB regression gate also runs availability interleavings and cold/warm
read verification. Host READ ONLY transactions and both PDO buffering modes are covered.

With the release-artifact prerequisites above installed, run this from the checkout:

```bash
bash tools/ci/test-shell-paths.sh '1.0.0-rc.3'
```

It runs the actual PHP runtime and release-artifact gate commands for empty/nonempty
`CDPATH` and absolute/relative `TMPDIR`, using relative and absolute script invocation
from the checkout and a different working directory. It also directly invokes both
release-artifact regression suites and the archive verifier, without the gate wrapper,
with absolute/relative `TMPDIR` and nonempty `CDPATH`. All 28 cases use paths with spaces
and reject Composer temporary-directory diagnostics even when the command exits zero.
Pass the configured RC target, or an empty argument when no target is active.
Working-tree shell edits are applied only to that disposable fixture. The real
source index and working tree are left untouched. CI runs the same command.
