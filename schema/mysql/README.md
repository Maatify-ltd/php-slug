# Slug Ordered MySQL-Compatible Package Schema

`001_slug_rc1.sql` is the published RC1 base schema. `002_operational_reporting_indexes.sql`
is the additive operational-reporting index asset published with v1.0.0-rc.2. The
published RC1 artifact does not contain `002`; it is applied when moving an existing
RC1 installation to the RC2 Runtime. The Host owns
the injected `PDO` connection and is responsible for selecting a
MySQL-compatible server that satisfies the capability contract in the
[Package Reference](../../SLUG_PACKAGE_REFERENCE.md#4-runtime-and-platform-contract).
The package does not create a connection, inspect a product/version
string, or choose a vendor-specific schema variant.

The schema uses direct PDO-compatible InnoDB semantics, `utf8mb4_bin` for
exact-safe text, and `ascii_bin` for closed ASCII tokens and keys. Enum,
status, role, event, pointer, and cross-field invariants remain package-owned;
the schema intentionally does not require generated columns, `CHECK`
enforcement, or native JSON functions.

## Installation and upgrade

Apply the ordered SQL assets through the Host's migration or bootstrap process before starting the persisted runtime.

| Starting point | Assets to apply |
|---|---|
| Fresh database | `001_slug_rc1.sql`, then `002_operational_reporting_indexes.sql`, then `003_registry_lock_projection.sql` |
| Existing published RC1 schema | `002_operational_reporting_indexes.sql`, then `003_registry_lock_projection.sql` |
| Existing published RC2 schema | `003_registry_lock_projection.sql` |

`003_registry_lock_projection.sql` was introduced after the published RC2 schema. It creates the internal InnoDB Registry lock-key projection, then backfills existing keys and anchors and records completion in one atomic SQL statement. The runtime checks the `id` primary key, the full ordered unique `uk_registry_lock_partition` index and the completion marker.

For an installed database, stop Slug traffic and wait for in-flight Slug transactions. Run `003` outside a caller-managed transaction. Stop at the first SQL error and close the failed migration session before retrying. Resume traffic only after the matching runtime is deployed and its schema verification succeeds.

The package maintains the projection through its injected PDO as part of the Registry write transaction. The Host uses the public lifecycle API to mutate Registry state. All foreign keys are package-local.

The schema assets are deliberately not vendor-specific migrations and must not be
replaced with a MariaDB or MySQL variant. CI database versions are
verification targets only; runtime acceptance is capability-based.

## Canonical integration infrastructure

Integration, System, complete-suite, and Consumer Harness verification use the
canonical lifecycle from a [repository checkout](../../CONTRIBUTING.md#prerequisites-for-local-development):

```text
compose.integration.yml
→ tools/ci/run-integration.sh
```

The lifecycle starts the pinned `mysql:8.0.36` image with one isolated Compose
project per run, discovers a dynamic loopback port, passes Integration-scoped
non-production credentials through the process environment, probes readiness
with PDO, and tears down the disposable state on success or failure. Developers
do not start MySQL manually or configure a database endpoint themselves.

Run these commands from the repository root:

```bash
composer test:integration
bash tools/ci/run-gate.sh test-system
composer test
bash tools/ci/run-gate.sh consumer
```

CI and the Consumer Verification Harness consume the same Compose definition and
orchestration. The PHP runner remains on the host or CI runner; no Host database,
persistent developer state, or production credential is used.
