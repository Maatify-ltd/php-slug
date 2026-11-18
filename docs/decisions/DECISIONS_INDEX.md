# Decision Index

This file is the canonical current-state discovery index for durable engineering decisions owned by `Maatify-ltd/php-slug`.

It is a discovery registry only. It is not a Decision Record, does not duplicate decision rationale, does not replace `STANDARDS_MANIFEST.md`, and does not create a new engineering policy.

## Current State

The active decisions cover profile compatibility and generated candidates, shared pagination, Registry lock boundaries, PDO session compatibility and autocommit admission, read-safe PDO observations, Unicode NFC closure, and verification of distributed releases.

## Registered Decisions

| Decision ID | Title | Status | Scope / Concern | Decision Record | Canonical Contract / Current Owner | Supersedes | Superseded By |
|---|---|---|---|---|---|---|---|
| `DEC-001` | Built-in Profile Runtime Compatibility | `ACTIVE` | `/` — Built-in slug profile runtime compatibility | [DEC-001_BUILTIN_PROFILE_RUNTIME_COMPATIBILITY.md](DEC-001_BUILTIN_PROFILE_RUNTIME_COMPATIBILITY.md) | `SLUG_PACKAGE_REFERENCE.md` §§4–5 | — | — |
| `DEC-002` | Shared Pagination Persistence Trial | `SUPERSEDED` | `/` — Shared management pagination dependency and public pagination types | [DEC-002_SHARED_PAGINATION_PERSISTENCE_TRIAL.md](DEC-002_SHARED_PAGINATION_PERSISTENCE_TRIAL.md) | `SLUG_PACKAGE_REFERENCE.md` §§3–4, 6.2, 10 | — | `DEC-010` |
| `DEC-003` | Registry Lock Boundaries | `SUPERSEDED` | `/` — InnoDB lock boundaries for Registry lifecycle current reads | [DEC-003_REGISTRY_LOCK_PROJECTION.md](DEC-003_REGISTRY_LOCK_PROJECTION.md) | `SLUG_PACKAGE_REFERENCE.md` §§9, 10.2 and `schema/mysql/README.md` | — | `DEC-005` |
| `DEC-004` | Release Artifact Verification | `ACTIVE` | `/` — Verification of candidate and published release artifacts | [DEC-004_RELEASE_ARTIFACT_VERIFICATION.md](DEC-004_RELEASE_ARTIFACT_VERIFICATION.md) | `CONTRIBUTING.md` — Release Artifact Verification | — | — |
| `DEC-005` | Registry Lock Projection Index | `ACTIVE` | `/` — InnoDB lock boundaries for Registry lifecycle current reads | [DEC-005_REGISTRY_LOCK_PROJECTION_INDEX.md](DEC-005_REGISTRY_LOCK_PROJECTION_INDEX.md) | `SLUG_PACKAGE_REFERENCE.md` §§9, 10.2 and `schema/mysql/README.md` | `DEC-003` | — |
| `DEC-006` | PDO Session Compatibility | `ACTIVE` | `/` — Unbuffered PDO and transaction snapshot compatibility | [DEC-006_PDO_SESSION_COMPATIBILITY.md](DEC-006_PDO_SESSION_COMPATIBILITY.md) | `SLUG_PACKAGE_REFERENCE.md` §§4, 9, 12 | — | — |
| `DEC-007` | Custom Profile Generated Candidates | `ACTIVE` | `/` — Profile-owned canonical grammar in bounded generated allocation | [DEC-007_CUSTOM_PROFILE_GENERATED_CANDIDATES.md](DEC-007_CUSTOM_PROFILE_GENERATED_CANDIDATES.md) | `SLUG_PACKAGE_REFERENCE.md` §5.3 | — | — |
| `DEC-008` | Unicode NFC Closure | `ACTIVE` | `/` — Built-in Unicode normalization after lowercase | [DEC-008_UNICODE_NFC_CLOSURE.md](DEC-008_UNICODE_NFC_CLOSURE.md) | `SLUG_PACKAGE_REFERENCE.md` §5.3 | — | — |
| `DEC-009` | Read-safe PDO Observation | `ACTIVE` | `/` — Query verification and consistent availability | [DEC-009_READ_SAFE_PDO_OBSERVATION.md](DEC-009_READ_SAFE_PDO_OBSERVATION.md) | `SLUG_PACKAGE_REFERENCE.md` §§9–10 | — | — |
| `DEC-010` | Persistence RC.2 Pagination Trial | `ACTIVE` | `/` — Shared management pagination dependency and public pagination types | [DEC-010_PERSISTENCE_RC2_PAGINATION_TRIAL.md](DEC-010_PERSISTENCE_RC2_PAGINATION_TRIAL.md) | `SLUG_PACKAGE_REFERENCE.md` §§3–4, 6.2, 10 | `DEC-002` | — |
| `DEC-011` | PDO Autocommit Admission | `ACTIVE` | `/` — Persisted operation admission and Host transaction ownership | [DEC-011_PDO_AUTOCOMMIT_ADMISSION.md](DEC-011_PDO_AUTOCOMMIT_ADMISSION.md) | `SLUG_PACKAGE_REFERENCE.md` §§9, 12 | — | — |
