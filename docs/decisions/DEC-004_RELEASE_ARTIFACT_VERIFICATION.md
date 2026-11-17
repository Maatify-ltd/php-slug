# DEC-004 — Release Artifact Verification

| Field | Value |
|---|---|
| Decision ID | `DEC-004` |
| Status | `ACTIVE` |
| Scope | `/` |
| Concern | Qualifying and verifying distributed Slug releases |
| Date | `2026-11-17` |
| Decision authority | Repository owner authorized the Release Artifact Verification gate through PR #46 and directed remediation against the pinned standards. |

## Context

The same repository tree supplies the Git and Composer archives, bilingual consumer documentation, and the release qualification evidence. The tree can have an active exact release target while Packagist still distributes an earlier RC. A successful source archive check cannot establish what a fresh external project later receives from Composer.

## Decision

Use the repository-owned Release Artifact Verification command in CI and locally. An active RC requires a single canonical exact install example in each README's reserved installation section and an exact release-target declaration in each reserved publication section. Any published earlier-version guidance belongs outside the reserved sections and identifies the earlier release explicitly. With no active release target, the same local and CI entry point checks that the source documents do not announce one.

The release gate checks the exact candidate commit and the archive payload. The Lead reviews the consumer-facing claims in context on that same commit. After publication, verify the exact Packagist version with observed Composer `dist` metadata and the qualified release bytes in an isolated external project.

## Rationale

A single command for local and CI verification lets maintainers reproduce both paths. The reserved sections make the candidate identity and install example unambiguous while allowing published-version guidance to remain visible. Separating prepublication Git/Composer archive checks from the external installation check verifies both what Slug prepared and what Packagist actually distributes.

## Consequences

- Maintain the release-facing document shape and verification commands together.
- A missing, mismatched, or source-fallback published artifact fails verification for the selected release.
- Keep release authorization and the Consumer Verification Harness under their existing contracts.

## Canonical Contract / Current Owner

[CONTRIBUTING.md](../../CONTRIBUTING.md#6-release-artifact-verification) owns the commands and their operational prerequisites. The pinned [CI Workflow Standard](../php-engineering-standards/standards/packages/CI_WORKFLOW_STANDARD.md) owns release verification semantics.
