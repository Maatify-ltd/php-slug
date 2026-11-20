# DEC-012 — Owned Rollback Confirmation

| Field | Value |
|---|---|
| Decision ID | `DEC-012` |
| Status | `ACTIVE` |
| Scope | `/` |
| Concern | Outcome of explicit rollback requests in package-owned transactions |
| Date | `2026-11-20` |
| Decision authority | The repository owner approved the scoped behavior in [Issue #52](https://github.com/Maatify-ltd/php-slug/issues/52#issuecomment-7031731306). |

## Context

The internal PDO coordinator could return the value carried by a rollback request when PDO reported an inactive transaction. PDO can report that state after the server has rolled back a deadlock victim. A fault-injected reading can also report it while writes are pending. The study proved this gap at the coordinator boundary. Reachability through the supported Public API remains unproven.

## Decision

Return the value carried by an owned rollback request only after PDO reports an active transaction and its rollback call succeeds. If PDO reports no active transaction, use the existing `SlugTransactionParticipationException` to report that rollback could not be confirmed. Apply this requirement only to explicit rollback requests on a package-owned transaction. The Host controls the outer transaction when Slug participates through a savepoint.

## Rationale

A normal discard result needs confirmation at the coordinator boundary. The PDO state reading cannot establish how the transaction ended. The narrower check preserves exception precedence for cleanup after a callback or commit failure. An engine-specific outcome check would require an independent way to establish the identity and outcome of the original transaction.

## Consequences

- The inactive-state branch can raise an exception where it previously returned a value, including after a real server rollback.
- An exception reporting an unconfirmed outcome leaves the Host responsible for isolating or replacing the connection. The package performs no automatic retry.

## Canonical Contract / Current Owner

[Slug Package Reference §§9, 11.3, and 12](../../SLUG_PACKAGE_REFERENCE.md#9-persistence-transactions-and-concurrency) defines the transaction, Host, and exception contracts.
