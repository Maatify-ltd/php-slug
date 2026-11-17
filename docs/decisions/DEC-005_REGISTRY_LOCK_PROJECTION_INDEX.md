# DEC-005 — Registry Lock Projection Index

| Field | Value |
|---|---|
| Decision ID | `DEC-005` |
| Status | `ACTIVE` |
| Scope | `/` |
| Concern | InnoDB lock boundaries for Registry lifecycle current reads |
| Date | `2026-11-17` |
| Decision authority | The repository owner directed the Schema 003 compliance correction and closure of the supersession finding in PR #47. |
| Supersedes | [DEC-003](DEC-003_REGISTRY_LOCK_PROJECTION.md) |

## Context

DEC-003 established physical Scope and Binding lock partitions through an internal projection. Its composite clustered primary key conflicts with the pinned schema rule requiring `PRIMARY KEY (id)`. Schema 003 has yet to ship.

## Decision

Use a generated `BIGINT UNSIGNED` primary key `id`. Use the visible unique secondary index `uk_registry_lock_partition` over `(scope_id, scope_end, binding_partition, key_kind, slug)` for lock ordering. Keep the Scope separator, partition boundaries, completion marker, and atomic backfill defined in DEC-003.

Read anchors through full-key locking probes, then insert only missing anchors under the existing Scope and Binding locks. Keep the projection and authoritative writes in the same transaction on the Host PDO. The Host retains ownership of any outer transaction and rollback.

## Rationale

The ordered unique secondary index supports the original partition boundaries while the generated primary key satisfies the package schema rule. Exact point reads avoid the next-key locks a duplicate update on this secondary index can take around an existing anchor. Combining the point probes reduces round trips while retaining the individual predicates that determine the lock footprint.

## Consequences

- Fresh installs and upgrades from published schemas use the corrected Schema 003. The schema guard checks both keys and the completion marker using the database's reported capabilities.
- Registry identity and its existing unique constraints remain the final ownership authority. The public API, lifecycle semantics, and savepoint behavior retain their existing contracts.

## Canonical Contract / Current Owner

[Slug Package Reference](../../SLUG_PACKAGE_REFERENCE.md#9-persistence-transactions-and-concurrency) owns the runtime and transaction contract. The [ordered schema guide](../../schema/mysql/README.md#installation-and-upgrade) owns installation and upgrade instructions.
