# DEC-003 — Registry Lock Boundaries

| Field | Value |
|---|---|
| Decision ID | `DEC-003` |
| Status | `ACTIVE` |
| Scope | `/` |
| Concern | InnoDB lock boundaries for Registry lifecycle current reads |
| Date | `2026-11-16` |
| Decision authority | Repository owner Mohamed Abdulalim approved the physical lock boundary and additive migration. The Technical Lead selected the projection design. |

## Context

Slug lifecycle mutations need current Registry state even when the Host holds an older `REPEATABLE READ` snapshot. Reading missing keys or scanning a sparse range with an InnoDB locking read can lock an index gap whose bounds span neighboring Scopes or Bindings. A predicate scoped to one logical owner therefore does not by itself isolate the physical lock footprint.

## Decision

Introduce an internal InnoDB Registry lock-key projection through the ordered additive migration `003_registry_lock_projection.sql`. Its clustered key is `(scope_id, scope_end, binding_partition, key_kind, slug)`. Partition zero represents Scope occupancy and positive Binding IDs identify separate participant partitions. Lower and upper boundary rows anchor each partition. An immutable Scope end anchor sits beyond every Binding partition and preserves that physical boundary when the terminal Binding is purged. Gap-sensitive lifecycle reads use the projection for current occupancy and participant claims. A single atomic backfill statement projects existing keys and anchors and records completion for runtime verification. The package initializes new partitions when a Registry lifecycle operation first needs them.

Maintain the projection with package-owned writes on the same Host PDO and in the same transaction as the corresponding Registry changes. Registry identity and its existing unique constraints remain the final ownership authority. Public API, lifecycle semantics, Host isolation and transaction ownership, and package savepoint behavior retain their existing contracts.

## Rationale

Adding a logical filter or using an existing sparse Registry index cannot bound the gap next-key locking reads may take for an absent key. Physical anchors let the affected reads keep current-read semantics while constraining the targeted lock ranges. The internal projection makes those boundaries expressible without redesigning Registry ownership, and transactional maintenance keeps the projection and authoritative rows consistent through commits and rollbacks.

## Consequences

- The Host applies the additive migration and completes its backfill while Slug is quiesced, then verifies the upgraded schema before bringing the matching runtime into service.
- Registry writes through the package now maintain additional internal rows. The Host uses the public lifecycle API for changes to package-owned Registry state.
- Lock boundaries apply to the targeted Registry reads. Writers still contend on shared keys and transactions remain subject to the database's concurrency and lock behavior.

## Canonical Contract / Current Owner

[Slug Package Reference](../../SLUG_PACKAGE_REFERENCE.md#9-persistence-transactions-and-concurrency) owns the runtime and transaction contract. The [ordered schema guide](../../schema/mysql/README.md#installation-and-upgrade) owns installation and upgrade instructions.
