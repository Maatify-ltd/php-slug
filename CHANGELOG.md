# Changelog

All notable changes to `maatify/php-slug` are documented here.
The format follows Keep a Changelog conventions and Semantic Versioning.

## 1.0.0-rc.3

### Added

- Added `003_registry_lock_projection.sql` to backfill internal Registry lock keys and constrain targeted lifecycle reads to their Scope and Binding boundaries. See the [schema upgrade guide](schema/mysql/README.md#installation-and-upgrade).

### Changed

- The PHP constraint is now `>=8.4.27 <8.5.0 || >=8.5.12 <9.0.0`. CI verifies PHP 8.4 and 8.5.
- Management pagination replaces `maatify/persistence` with `maatify/php-persistence` at `1.0.0-rc.1`. Update `PageRequest`, `SortDirectionEnum`, and `PageResultDTO` imports.

### Fixed

- Lifecycle mutations reject a missing or incomplete Schema 003 with `SlugRuntimeCompatibilityException` before taking lifecycle locks.
- Declared `ext-filter` for runtime integer validation and hardened the persisted lifecycle example against deleting pre-existing Slug tables or views.
- Made PDO consumer reads consistent across Scope, Binding, and Registry state. Fixed transition, lifecycle mutations, and operation replay when the Host transaction has an older snapshot.
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
