# DEC-008 — Unicode NFC Closure

| Field | Value |
| --- | --- |
| Decision ID | `DEC-008` |
| Status | `ACTIVE` |
| Scope | `/` — Built-in `unicode-v1` canonicalization |
| Date | `2026-11-18` |
| Decision authority | The repository owner authorized remediation of the four audit findings at `20fcc88`, preserving the public API and profile identity. |

## Context

ICU lowercase can turn an NFC input into a decomposed result. The existing `unicode-v1` pipeline then rejected a valid source or claim when constructing its NFC-only `Slug`. For example, uppercase `J` with a combining caron and uppercase Greek iota with diaeresis and acute did not reach their canonical lowercase NFC forms.

## Decision

Restore NFC after ICU lowercase, before canonical grammar, length checks, or generated truncation. Apply the same pipeline to generation, claims, and lookup. Keep the `unicode-v1` key, alphabet, 160-code-point limit, and public signatures.

This explicitly refines the `unicode-v1` pipeline recorded at the RC.1 boundary in [DEC-001](DEC-001_BUILTIN_PROFILE_RUNTIME_COMPATIBILITY.md): `NFC → Any-Lower → NFC → canonical rules`. DEC-001's capability-based runtime admission remains active. The ASCII pipeline is unchanged.

## Rationale

NFC is already a `Slug` invariant. Normalizing the lowercase result closes the existing operation over that invariant; it does not introduce a new equivalence policy. Previously successful Unicode outputs already satisfied NFC and remain identical. A new profile key would leave the defect in the existing built-in profile and force consumer migration for a correctness repair.

## Consequences

- Previously rejected inputs can now produce their intended NFC lowercase slug.
- The final NFC token determines length and truncation; re-canonicalization is idempotent.
- No schema migration, dependency, or runtime version pin is added.
- Regression tests retain composed/decomposed examples and the 160/161-code-point boundaries.

## Canonical Contract / Current Owner

[Slug Package Reference §5.3](../../SLUG_PACKAGE_REFERENCE.md#53-profiles-safety-and-length) owns the current text contract.
