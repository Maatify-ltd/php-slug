# DEC-006 — PDO Session Compatibility

| Field | Value |
| --- | --- |
| Decision ID | `DEC-006` |
| Status | `ACTIVE` |
| Scope | `/` — Persisted PDO connection and Host transaction participation |
| Date | `2026-11-17` |
| Decision authority | The repository owner authorized remediation of the PDO and snapshot audit findings following PR #47, with Host transaction ownership preserved. |

## Context

An unbuffered PDO connection could still have a live capability-probe result when the package tried to reuse it or clean up its transaction. MariaDB with session snapshot isolation enabled could reject a locking read of a row committed after the Host established a snapshot, including an error that prevents savepoint cleanup.

## Decision

Release each probe result before the next command uses that connection. When the database exposes `innodb_snapshot_isolation`, require it to be disabled before package mutation, including after successful schema verification. Non-locking reads support either setting. PDO failures during the setting check propagate unchanged. Leave the setting and the outer transaction under Host control.

## Rationale

Closing the result supports unbuffered operation with the same connection and transaction boundaries. Current locking reads are required by the lifecycle's consistency and lock-order guarantees. Checking the session before package mutation preserves the Host's transaction and allows a typed compatibility error instead of relying on the server's failure and cleanup behavior.

## Consequences

- The package supports either PDO buffering mode and uses the same adapter for qualified database sessions.
- Hosts using the MariaDB setting configure it before starting a write transaction. The package does not change the setting or retry the Host transaction.
- MySQL and MariaDB run the same maintained PDO and snapshot regression suite.

## Canonical Contract / Current Owner

[Slug Package Reference §§4, 9, and 12](../../SLUG_PACKAGE_REFERENCE.md#9-persistence-transactions-and-concurrency) defines the public runtime and exception contract.
