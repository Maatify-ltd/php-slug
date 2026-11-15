# Maatify Engineering Standards Adoption Manifest

## Adoption Record

- **Overall Resolution Status:** `VALID`
- **Adoption Model:** Selective pinned adoption
- **Upstream Repository:** `https://github.com/Maatify-ltd/php-engineering-standards.git`
- **Adoption Commit:** `7f73468922d4e58b5d86cd9511e1081c28acf420`
- **Adoption Date:** `2026-11-15`
- **Latest Applicability Re-evaluation:** `2026-11-15` against the exact Adoption Commit.
- **Additional Standards:** None
- **Explicit Exceptions/Overrides:** None

## Pinned Adoption Control Set

All files below are copied from the exact Adoption Commit recorded above.

- [Standards Adoption Standard](standards/STANDARDS_ADOPTION_STANDARD_AR.md) — `std-standards-adoption` `5.0.1`
- [Composer Package Profile](standards/profiles/COMPOSER_PACKAGE_PROFILE.md) — `composer-package` `4.0.0`
- [Repository Governance Profile](standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md) — `repository-governance` `3.0.0`

## Pinned Operational Assets Set

| Kind | Upstream Path | Local Path | SHA-256 |
|---|---|---|---|
| `skill` | `.agents/skills/maatify-standards-context-lifecycle/SKILL.md` | `.agents/skills/maatify-standards-context-lifecycle/SKILL.md` | `e86791d1d0e865610e4d77a557b3820624446ee0272cf03a49eee16aabba204b` |
| `skill` | `.agents/skills/maatify-strict-release-acceptance/SKILL.md` | `.agents/skills/maatify-strict-release-acceptance/SKILL.md` | `95ed8a461577bf8a55ce421111cf074edff5d7f0c98fa8893371366099a2839d` |
| `deterministic-helper` | `.agents/helpers/maatify-governance-context.php` | `.agents/helpers/maatify-governance-context.php` | `25852945a6d9fac44c8cfc55039b5a27e3aeb289e964c3e092b490e359ee5d59` |

## Active Profiles

### Activation 1: `composer-package`

- **Profile Version:** `4.0.0`
- **Scope:** `/`
- **Resolution Status:** `VALID`
- **Exception State:** `NONE`
- **Activation:** [Composer Package Profile](standards/profiles/COMPOSER_PACKAGE_PROFILE.md)

### Activation 2: `repository-governance`

- **Profile Version:** `3.0.0`
- **Scope:** `/`
- **Resolution Status:** `VALID`
- **Exception State:** `NONE`
- **Activation:** [Repository Governance Profile](standards/profiles/REPOSITORY_GOVERNANCE_PROFILE.md)

## Resolved Applicable Standards Set

The table records the final Stage 2 result for both activations.

| Standard ID | Version | Local path | Resolution provenance |
|---|---:|---|---|
| `std-package-building` | `4.0.0` | [PACKAGE_BUILDING_STANDARD.md](standards/packages/PACKAGE_BUILDING_STANDARD.md) | Direct requirement of composer-package. Slug owns a reusable PDO/MySQL package, including the schema for scoped slug lifecycle. |
| `std-composer-package` | `7.0.0` | [COMPOSER_PACKAGE_STANDARD.md](standards/packages/COMPOSER_PACKAGE_STANDARD.md) | Direct requirement of composer-package. The Composer manifest is at the standalone package root. |
| `std-ci-workflow` | `7.0.0` | [CI_WORKFLOW_STANDARD.md](standards/packages/CI_WORKFLOW_STANDARD.md) | Direct requirement of composer-package. CI verifies the package, including its database behavior. |
| `std-library-presentation` | `6.0.0` | [LIBRARY_PRESENTATION_STANDARD.md](standards/packages/LIBRARY_PRESENTATION_STANDARD.md) | Direct requirement of composer-package. Slug owns its consumer documentation and distribution presentation. |
| `std-testing` | `3.0.0` | [TESTING_STANDARD.md](standards/testing/TESTING_STANDARD.md) | Direct requirement of composer-package. Slug owns executable canonicalization, lifecycle, and persistence contracts. |
| `std-php-source-documentation` | `1.0.0` | [PHP_SOURCE_DOCUMENTATION_STANDARD.md](standards/php/PHP_SOURCE_DOCUMENTATION_STANDARD.md) | Direct requirement of composer-package. Slug owns maintained PHP source. |
| `std-php-coding-style` | `1.0.2` | [PHP_CODING_STYLE_STANDARD.md](standards/php/PHP_CODING_STYLE_STANDARD.md) | Direct requirement of composer-package. Slug owns maintained PHP source. |
| `std-documentation-lifecycle` | `3.1.1` | [DOCUMENTATION_LIFECYCLE_STANDARD_AR.md](standards/governance/DOCUMENTATION_LIFECYCLE_STANDARD_AR.md) | Direct requirement of both profiles. Slug maintains consumer documents and repository governance records. |
| `std-decision-governance` | `1.0.0` | [DECISION_GOVERNANCE_STANDARD_AR.md](standards/governance/DECISION_GOVERNANCE_STANDARD_AR.md) | Direct requirement of repository-governance. Slug governs durable package decisions. |
| `std-ai-collaboration-workflow` | `13.0.0` | [AI_COLLABORATION_WORKFLOW_AR.md](standards/ai/AI_COLLABORATION_WORKFLOW_AR.md) | Direct requirement of repository-governance. Slug follows the Maatify engineering workflow. |
| `std-github-phase-stack-workflow` | `5.0.0` | [GITHUB_PHASE_STACK_WORKFLOW_AR.md](standards/GITHUB_PHASE_STACK_WORKFLOW_AR.md) | Direct requirement of repository-governance. Slug follows the Maatify GitHub phase workflow. |

## Resolution Summary

Both Profiles activate directly at the standalone package root and neither
inherits another Profile. Stage 1 resolves nine Composer Package requirements
and four Repository Governance requirements. Both reference Documentation
Lifecycle, giving 12 unique candidates.

Stage 2 excludes External Provider Verification because Slug owns no
provider-specific contract. Its built-in normalization uses PHP extensions,
and its persistence uses Host-supplied PDO and package-owned MySQL tables.
The 11 applicable Standards listed above include the corresponding schema,
persistence, and verification requirements.

This upgrade replaces Adoption Commit
`7dd9d1d02b53013da0906c729dab4f667afeefb4`. It records resolution of the
Standards and operational assets for this package.
