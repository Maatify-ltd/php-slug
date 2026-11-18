# DEC-002 — Shared Pagination Persistence Trial

| Field | Value |
|---|---|
| Decision ID | `DEC-002` |
| Status | `SUPERSEDED` |
| Scope | `/` |
| Concern | Shared management pagination dependency and public pagination types |
| Date | `2026-11-15` |
| Decision authority | Repository owner Mohamed Abdulalim |
| Superseded By | [DEC-010](DEC-010_PERSISTENCE_RC2_PAGINATION_TRIAL.md) |

## Context

Slug delegates management pagination to a shared package. The dependency used by the published Slug `v1.0.0-rc.2`, `maatify/persistence`, is abandoned. The pinned Package Building Standard identifies `maatify/php-persistence` as the shared implementation and allows an exact, temporary trial of its latest published release candidate while it has no stable release.

The [Persistence release tag](https://github.com/Maatify-ltd/php-persistence/releases/tag/v1.0.0-rc.1) and the [Packagist version](https://packagist.org/packages/maatify/php-persistence#v1.0.0-rc.1) identify `1.0.0-rc.1` at `34c3b874525d36ae064c162cdd553bc75d18772b`. Its [Package Reference](https://github.com/Maatify-ltd/php-persistence/blob/34c3b874525d36ae064c162cdd553bc75d18772b/PHP_PERSISTENCE_PACKAGE_REFERENCE.md) covers the shared Pagination API needed by Slug.

## Decision

Pin `maatify/php-persistence` to exactly `1.0.0-rc.1` for the checkout trial. Adopt its `PageRequest` and `SortDirectionEnum` for inputs and `PageResultDTO` for results. Slug continues to own the SQL, filters, count semantics, and row mapping. Its schema and transaction coordinator remain owned by Slug.

Keep `minimum-stability: stable` and `prefer-stable: true`. The external consumer harness permits the two pre-stable packages explicitly and verifies the installed Persistence version, source reference, and dist installation.

## Rationale

The replacement continues to provide shared count, paging, and sorting behavior. Keeping Slug's SQL and transaction boundary preserves domain behavior and Host transaction ownership. Pinning a single published Persistence build makes the trial reproducible while containing the pre-stable exception.

## Consequences

- Checkout consumers must update pagination imports and concrete result types. The page and pagination metadata returned by `toArray()` and JSON retain the same shape.
- The published Slug `v1.0.0-rc.2` keeps its original dependencies and versioned documentation.
- The trial ends when Persistence publishes a stable release. Selecting a stable replacement requires capability and integration review. Any Slug release remains a separate owner decision.

## Canonical Contract / Current Owner

[Slug Package Reference](../../SLUG_PACKAGE_REFERENCE.md) §§3–4, 6.2, and 10 defines the current installation, runtime, and pagination contracts.
