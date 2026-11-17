# DEC-007 — Custom Profile Generated Candidates

| Field | Value |
| --- | --- |
| Decision ID | `DEC-007` |
| Status | `ACTIVE` |
| Scope | `/` — Generated lifecycle allocation with registered custom profiles |
| Date | `2026-11-17` |
| Decision authority | The repository owner authorized remediation of the strict RC.3 acceptance findings on `4130ebf`, preserving Host transaction ownership and lock ordering. |

## Context

Generated allocation imposed the built-in Unicode grammar on every registered profile and rejected an entire sequence when the profile rejected a numeric suffix. A valid dotted base could fail before allocation, and a profile that disallowed numeric suffixes could not allocate even a free base.

## Decision

Keep the profile-generated base first, enforce shared safety and length boundaries during candidate preparation, and apply the bounded `-2` through `-1000` algorithm without imposing built-in punctuation rules. Retain only suffixes accepted by the selected profile. `InvalidArgumentException` and `SlugValidationException` from suffix validation mean that candidate is rejected; other failures propagate. An occupied or reserved base with no accepted alternative follows the existing allocation-exhaustion contract.

Prepare the accepted sequence before mutation locks. All generated assignment, change, scope transition, and transfer-replacement paths use the same preparation. Keep existing lock ordering, database collation equality, and transaction ownership.

## Rationale

The profile owns canonical grammar, while the package owns bounded allocation. Filtering profile-rejected suffixes preserves this separation and lets a valid free base succeed. Preparing a bounded sequence up front preserves the established lock order. Catching only validation failures prevents unexpected profile errors from being hidden as ordinary exhaustion.

## Consequences

- Built-in profile candidate order and the 160-code-point bound remain unchanged.
- Custom profiles may admit all, some, or none of the numeric suffixes.
- Custom implementations use the documented validation exception families to reject a candidate and retain responsibility for their source, claim, and lookup rules.
- Regression tests cover all four public generated paths and Host rollback on MySQL and MariaDB.

## Canonical Contract / Current Owner

[Slug Package Reference §5.3](../../SLUG_PACKAGE_REFERENCE.md#53-profiles-safety-and-length) owns profile safety and generated allocation semantics.
