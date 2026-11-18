# DEC-011 — PDO Autocommit Admission

| Field | Value |
|---|---|
| Decision ID | `DEC-011` |
| Status | `ACTIVE` |
| Scope | `/` |
| Concern | Persisted operation admission and Host transaction ownership |
| Date | `2026-11-18` |
| Decision authority | The repository owner approved this condition for PR #51. |

## Context

With `autocommit=OFF` and no active transaction, package table reads can open an implicit transaction. Before this guard, a cold mutation could participate in that transaction while a warm mutation could start and commit its own transaction. The outcome depended on which capability checks had already run.

## Decision

Check the current PDO session on every operation that reaches persistence. If `autocommit=OFF` and PDO has no active transaction, raise `SlugRuntimeCompatibilityException` before accessing package tables. A Host that disables autocommit must open its transaction before calling Slug. Slug leaves the session setting and any active Host transaction under Host control.

With autocommit enabled and no Host transaction, the library owns the transaction for its mutation. Read checks continue to use the read-only boundary in [DEC-009](DEC-009_READ_SAFE_PDO_OBSERVATION.md). The compatibility checks in [DEC-006](DEC-006_PDO_SESSION_COMPATIBILITY.md) continue to apply.

## Rationale

Rejecting the ambiguous session state at entry gives cold and warm calls the same transaction ownership. The Host can keep its preferred session setting by opening a transaction explicitly. The check avoids starting an implicit transaction during package admission.

## Consequences

- With autocommit disabled, the Host starts the transaction and decides when to commit or roll back.
- Connection checks remain valid for both buffered and unbuffered PDO. The check runs before lifecycle row locks and keeps their order unchanged.
- Stateless operations continue without PDO admission.

## Canonical Contract / Current Owner

[Slug Package Reference §§9 and 12](../../SLUG_PACKAGE_REFERENCE.md#9-persistence-transactions-and-concurrency) defines the PDO admission, transaction, and exception contract.
