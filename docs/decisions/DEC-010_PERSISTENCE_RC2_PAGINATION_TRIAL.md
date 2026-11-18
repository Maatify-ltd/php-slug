# DEC-010 — Persistence RC.2 Pagination Trial

| Field | Value |
|---|---|
| Decision ID | `DEC-010` |
| Status | `ACTIVE` |
| Scope | `/` |
| Concern | Shared management pagination dependency and public pagination types |
| Date | `2026-11-18` |
| Decision authority | The repository owner directed the Persistence RC update for PR #51. |
| Supersedes | [DEC-002](DEC-002_SHARED_PAGINATION_PERSISTENCE_TRIAL.md) |

## Context

Slug delegates management pagination to `maatify/php-persistence`. The pinned Package Building Standard permits an exact, temporary trial of its latest published RC while Persistence has no stable release. The [GitHub release](https://github.com/Maatify-ltd/php-persistence/releases/tag/v1.0.0-rc.2) and [Packagist version](https://packagist.org/packages/maatify/php-persistence#v1.0.0-rc.2) identify `1.0.0-rc.2` at `71dc61548e48fd053376db69149a00bc87069b70`. This release fixes PDO error handling and integer boundary checks in Ordering. Its [Package Reference](https://github.com/Maatify-ltd/php-persistence/blob/71dc61548e48fd053376db69149a00bc87069b70/PHP_PERSISTENCE_PACKAGE_REFERENCE.md#33-pagination-capability--maatifyphppersistencepagination) defines the Pagination API used by Slug.

## Decision

Pin `maatify/php-persistence` to exactly `1.0.0-rc.2` for the checkout trial. Keep `minimum-stability: stable` and `prefer-stable: true`. The external Consumer Harness explicitly permits the exact pre-release dependency and verifies its version, source reference, and dist identity.

Retain the shared `PageRequest`, `SortDirectionEnum`, `PageResultDTO`, and `PdoPaginator` contracts. Slug continues to own its SQL, filters, count semantics, row mapping, schema, and transaction coordination.

## Rationale

Adopting the latest published candidate keeps the trial current under the pinned standard. An exact pin makes the verified dependency reproducible. The upstream runtime change is in Ordering, and the Pagination API used by Slug is available at the same release revision.

## Consequences

- Checkout consumers use Persistence RC.2 for management pagination. The published Slug `v1.0.0-rc.2` keeps its original dependency and versioned documentation.
- The temporary exception ends when Persistence publishes a stable release. Adopting a stable dependency requires capability and integration review. Publishing a Slug release requires separate owner authorization.

## Canonical Contract / Current Owner

[Slug Package Reference](../../SLUG_PACKAGE_REFERENCE.md) §§3–4, 6.2, and 10 defines the current installation, runtime, and pagination contracts.
