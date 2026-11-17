# DEC-009 — Read-safe PDO Observation

| Field | Value |
| --- | --- |
| Decision ID | `DEC-009` |
| Status | `ACTIVE` |
| Scope | `/` — PDO capability verification and advisory availability reads |
| Date | `2026-11-18` |
| Decision authority | The repository owner authorized remediation of the four audit findings at `20fcc88`, preserving lock order and Host transaction ownership. |

## Context

A cold public query ran rollback-protected DML capability probes, so SELECT-only accounts and Host READ ONLY transactions failed before reading. Availability also observed a claim and its owner through different statements; a concurrent release, purge, reuse, or transfer could yield a fractured result or false invariant error.

## Decision

Separate read-only connection/schema metadata validation from write-probe validation. Public queries neither write package rows nor start, savepoint, commit, or roll back transactions. Before the first mutation on the guard, run the existing transaction/savepoint and schema write probes; cache their success separately. Continue checking the session's current-locking-read requirement before every mutation, including after a successful read or write.

PDO availability uses the existing consistent Scope/claim/owner observation used by resolution. It retains profile validation and real owner-invariant failures, without acquiring lifecycle locks or opening another connection. Keep existing constructor signatures and legacy repository contracts; an internal observation interface is used when provided by the PDO adapter.

## Rationale

Metadata reads establish the prerequisites of a query without requiring write privileges. A distinct successful-write cache prevents a harmless query from bypassing mutation verification. A single SQL observation provides a coherent advisory result under the Host's isolation level; taking locks or starting a transaction merely to answer availability would complicate consumer use and transaction ownership.

## Consequences

- SELECT-only queries and queries inside a Host READ ONLY transaction work with cold or warm guards and either PDO buffering mode.
- Read validation checks the package schema and completed Registry projection, but does not claim to prove write privileges or constraint enforcement. Mutation probes remain required before writing.
- Availability remains advisory; the unique Registry constraint remains final claim authority.
- Write lock order, shared PDO ownership, rollback behavior, and public signatures stay unchanged.
- SELECT-only fixture accounts are provisioned only in disposable test services.

## Canonical Contract / Current Owner

[Slug Package Reference §§9–10](../../SLUG_PACKAGE_REFERENCE.md#9-persistence-transactions-and-concurrency) owns the PDO, transaction, and consistent-observation contracts. [DEC-006](DEC-006_PDO_SESSION_COMPATIBILITY.md) remains active for Host session requirements.
