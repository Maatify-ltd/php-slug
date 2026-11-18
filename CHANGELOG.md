# Changelog

All notable changes to `maatify/php-slug` are documented here.
The format follows Keep a Changelog conventions and Semantic Versioning.

## 1.0.0-rc.3

### Added

- Added `003_registry_lock_projection.sql` to backfill internal Registry lock keys and constrain targeted lifecycle reads to their Scope and Binding boundaries. See the [schema upgrade guide](schema/mysql/README.md#installation-and-upgrade).

### Changed

- The PHP constraint is now `>=8.4.27 <8.5.0 || >=8.5.12 <9.0.0`. CI verifies PHP 8.4 and 8.5.
- Management pagination replaces `maatify/persistence` with `maatify/php-persistence` at `1.0.0-rc.2`. Update `PageRequest`, `SortDirectionEnum`, and `PageResultDTO` imports.

### Fixed

- Matched same-Binding lifecycle claims using Registry collation equality across changes, aliases, restoration, release, transfers and their replacements, preserving stored slug bytes, no-op behavior, and Host transaction ownership.
- Restored NFC after Unicode lowercasing before grammar, length checks, and generated truncation, preserving profile keys and existing valid outputs.
- Made advisory PDO availability observe the claim and its owner together during concurrent release, purge, reuse, and transfer.
- Kept public PDO query verification read-only; first mutations still perform the separate rollback-protected write probes.
- Made local gate and archive scripts independent of `CDPATH`, relative `TMPDIR`, and spaces in temporary paths, including child commands reached through direct script invocation, with maintained real-command regressions.
- Resolved relative temporary-directory names beginning with `-` as literal paths in all seven shell entry points and the published-artifact driver, including the name `-`, with maintained startup probes for absolute paths, spaces, and symlinks.

- Preserved custom profile grammars during generated allocation across assignment, change, scope transition, and transfer replacement. Profile-rejected numeric suffixes no longer prevent allocation of a free base.
- Applied input-safety and 160-code-point boundaries in the runnable custom-profile example, and corrected the management Criteria inventory and History sorting reference.
- Lifecycle mutations reject a missing or incomplete Schema 003 with `SlugRuntimeCompatibilityException` before taking lifecycle locks.
- Declared `ext-filter` for runtime integer validation and hardened the persisted lifecycle example against deleting pre-existing Slug tables or views.
- Made PDO consumer reads consistent across Scope, Binding, and Registry state. Fixed transition, lifecycle mutations, and operation replay when the Host transaction has an older snapshot.
- Closed capability-probe results before reusing unbuffered PDO connections. MariaDB writes require `innodb_snapshot_isolation=OFF` where available. Non-locking reads work with either setting, and connection failures retain their PDO exception type.
- Return `SlugNotFoundException` for absent mutation targets regardless of whether their Scope exists. Verify PDO and Schema 003 for persisted resolution, require enforced write constraints, and treat the package table prefix literally.
- Require the `CurrentSlugDTO` revision and current canonical Claim to match its Binding snapshot. A mismatched Claim raises `SlugInvalidArgumentException`. An equivalent Claim snapshot supplied as a separate object is accepted.
- Reject PDO session encodings that can change entity identities or result text, and report unsupported column casing as a connection compatibility error.
- Verify required column definitions, index keys and foreign keys before the first write. Reject foreign current Claims in management reads and replay, and enforce the declared roles and list element types in public result DTOs.
- Recheck persisted compatibility when the selected database changes. Reject replay revisions and current slugs that disagree with the after state, and reject inconsistent Registry ownership, Scope, and claim roles in `searchBindings()`.
- Reject persisted calls that could start an implicit transaction when `autocommit` is disabled and the Host has no active transaction. Reject incompatible additional required columns during schema verification.
- Reject replay snapshots with inconsistent before and after Binding identities, conflicting History, or transition and adoption evidence that disagrees with the operation. Verify participant IDs and roles against persisted operation evidence, current adoption and transfer against the target pointer, and History against the recorded sequence range.
- Accept compatible expression defaults on additional MySQL columns while continuing to reject generated columns.
- Kept management Registry results empty for a missing requested Binding and treated search prefixes literally with or without MySQL backslash escaping.

## [1.0.0-rc.2] - 2026-09-25

### Added

- Scope discovery, Scope operational summaries, and operational History/time-window reads.
- Additive operational-reporting indexes with an ordered fresh-install and published-RC1 upgrade path.
- Documentation, Extension Guide, and maintained custom-profile/persisted-lifecycle example closure.

## [1.0.0-rc.1] - 2026-09-24

### Added

- **Package Foundation:** Built-in profiles, exact claiming, generated allocation, and public API interfaces (`SlugTextServiceInterface`, `SlugProfileInterface`, `SlugLifecycleServiceInterface`, etc.).
- **Persistence & Schema:** PDO MySQL persistence, atomic transactions, CAS logic, result snapshots, and exception propagation.
- **Lifecycle & History:** Alias creation, scoped ownership, release, purge, scope transition, atomic transfer, and legacy adoption rules.
- **Resolution & Management:** Management queries with robust shared pagination (`maatify/persistence`).
- **Concurrency & Integration:** Lock ordering and safe concurrency behavior verified via integration tests.
- Added and updated the root `SLUG_PACKAGE_REFERENCE.md` to reflect the actual implemented public API and package boundaries.
- Created and updated `README.md`, `SECURITY.md`, `CONTRIBUTING.md`, and `CODE_OF_CONDUCT.md` to establish accurate baseline boundaries and requirements.

### Changed

- Removed exact ICU 74 and Unicode 15.1 host coupling for built-in profiles; runtime compatibility now uses capability and observable-behavior probes.
- Preserved the `ascii-v1` and `unicode-v1` algorithms and removed runtime compatibility skips from verification.
- **Release Lifecycle:** `v1.0.0-rc.1` is the first SemVer Release Candidate. It is Pre-Stable and does not create a Stable support line.
- **Publication Truth:** A CHANGELOG entry alone is not publication proof; Published state is determined by external Composer resolvability of the matching version through the approved distribution source.

[1.0.0-rc.2]: https://github.com/Maatify-ltd/php-slug/releases/tag/v1.0.0-rc.2
[1.0.0-rc.1]: https://github.com/Maatify-ltd/php-slug/releases/tag/v1.0.0-rc.1
