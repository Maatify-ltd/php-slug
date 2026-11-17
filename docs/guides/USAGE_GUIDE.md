# Maatify Slug — Usage Guide

> **Release target:** `1.0.0-rc.3` (Pre-Stable Release Candidate).
>
> [`SLUG_PACKAGE_REFERENCE.md`](../../SLUG_PACKAGE_REFERENCE.md) is the normative technical contract. This guide explains usage and does not create a competing contract.

## Fit / When to Use

Use `maatify/php-slug` when the package needs slug generation, canonicalization, or persistent scoped ownership with resolution and history. The stateless path is suitable for text that does not require persistence; the persisted path is suitable when the package owns the slug lifecycle and claims.

## Requirements

- PHP (Composer): `>=8.4.27 <8.5.0 || >=8.5.12 <9.0.0`. CI verifies PHP 8.4 and 8.5. Composer also admits newer PHP 8 minors within this range. Each newer minor needs its own security and compatibility evidence before it can be listed as supported.
- `ext-filter`, `ext-intl`, `ext-mbstring`, `ext-pdo`, and `ext-pdo_mysql`.
- Built-in profiles require the normalization and transliteration capabilities of `ext-intl`; those capabilities are verified at runtime. No exact ICU or Unicode version is required.
- The stateless path requires only the production Composer autoload.
- The persisted path requires a MySQL-compatible `PDO` connection and the package schema. Local verification uses the [disposable MySQL service from the repository checkout](../../CONTRIBUTING.md#prerequisites-for-local-development).

## Installation

At qualification, `1.0.0-rc.3` is unpublished and `1.0.0-rc.2` is available through Packagist. No Stable support line is established.

Once RC.3 is published to Packagist, install it with:

```bash
composer require maatify/php-slug:1.0.0-rc.3@RC maatify/php-persistence:1.0.0-rc.1@RC
```

Keep the project's `minimum-stability` set to `stable`. For the RC.2 migration and pagination changes, see [Installation in the README](../../README.md#installation). If you use RC.2, follow its [versioned usage guide](https://github.com/Maatify-ltd/php-slug/blob/v1.0.0-rc.2/docs/guides/USAGE_GUIDE.md).

## Non-goals / Host Boundaries

The package does not own Host entity persistence, entity existence, authentication, authorization, routing, URL construction, HTTP, SEO, or framework integration. The Host owns and configures the `PDO` connection, passes `ReservedSlugPolicyInterface` and `ClockInterface`, and defines application-specific entity identity and needs.

Consumers must not use package tables, repositories, or SQL as a Public API. See the [Package Reference](../../SLUG_PACKAGE_REFERENCE.md) for the complete contract and ownership boundaries.

## Construction Paths

### Stateless Canonicalization

```php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$text = SlugTextServiceFactory::create($profiles);
$result = $text->generateFromSource(
    new SlugProfileKey('ascii-v1'),
    'Hello, World!',
);
```

This path does not create a connection or read Host configuration. The runnable example is [`examples/canonicalization.php`](../../examples/canonicalization.php).

### Persisted Lifecycle

```php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$engine = SlugEngineFactory::create(
    $pdo,
    $profiles,
    $reservedPolicy,
    $clock,
);
```

The Host creates the `PDO` connection and applies the package schema in its environment. [`examples/persisted-lifecycle.php`](../../examples/persisted-lifecycle.php) demonstrates construction and cleanup through the canonical Integration environment.

On MariaDB, if the server exposes `innodb_snapshot_isolation`, set it to `OFF` on the PDO session before opening a transaction. See the [PDO and transaction contract](../../SLUG_PACKAGE_REFERENCE.md#9-persistence-transactions-and-concurrency).

## Capability Map

| Capability | Public API | Walkthrough | Example |
|---|---|---|---|
| Canonicalization / generation | `SlugTextServiceInterface` — `generateFromSource`, `canonicalizeClaim`, `canonicalizeLookup` | [Canonicalization walkthrough](#canonicalization-walkthrough) | [`examples/canonicalization.php`](../../examples/canonicalization.php) |
| Lifecycle ownership / mutation | `SlugEngine` and `SlugLifecycleServiceInterface` — `assignExact` and the persisted lifecycle path | [Persisted lifecycle walkthrough](#persisted-lifecycle-walkthrough) | [`examples/persisted-lifecycle.php`](../../examples/persisted-lifecycle.php) |
| Consumer reads | `checkAvailability`, `getCurrent`, `resolve` | [Consumer reads / resolution](#consumer-reads--resolution) | [`examples/persisted-lifecycle.php`](../../examples/persisted-lifecycle.php) |
| Scope discovery | `searchScopes(ScopeSearchCriteria)` | [Scope discovery](#scope-discovery) | [`examples/persisted-lifecycle.php`](../../examples/persisted-lifecycle.php) |
| Scope operational summary | `getScopeOperationalSummary(ScopeCriteria)` | [Scope operational summary](#scope-operational-summary) | [`examples/persisted-lifecycle.php`](../../examples/persisted-lifecycle.php) |
| Operational History / time windows | `searchHistory(HistorySearchCriteria)` | [Operational History](#operational-history) | [`examples/persisted-lifecycle.php`](../../examples/persisted-lifecycle.php) |
| Management / Operational Read | `SlugManagementQueryInterface` — management reads plus the reporting calls above | [Management operational reads](#management-operational-reads) | [`examples/persisted-lifecycle.php`](../../examples/persisted-lifecycle.php) |
| Custom profile extension | `SlugProfileInterface`, `SlugProfileRegistryInterface::register()` | [Custom profile extension](#custom-profile-extension) | [`examples/custom-profile.php`](../../examples/custom-profile.php) |

This map directs readers to usage; it does not repeat the complete inventory in the Package Reference.

## Canonicalization Walkthrough

**Input**

The text `Hello, World!` and `SlugProfileKey('ascii-v1')`.

**Public Call**

`SlugTextServiceInterface::generateFromSource(...)`.

**Result**

A `GeneratedSlugDTO` with the value `hello-world` is returned. The example checks the result and fails with `RuntimeException` if it differs.

**Boundary**

There is no persistence or transaction on this path; the example does not require Docker or a database. Profile and canonicalization rules are defined in [Package Reference §5](../../SLUG_PACKAGE_REFERENCE.md#5-identity-and-text).

## Persisted Lifecycle Walkthrough

### Lifecycle Ownership / Mutation

**Input**

`SlugScope(namespace: 'example', localeKey: null, contextKey: null)`, `SlugProfileKey('ascii-v1')`, the entity identity `article/example-1`, the candidate `hello-world`, and a valid `AuditContextDTO`.

**Public Call**

`SlugEngine::assignExact(new AssignExactCommand(...))`.

**Result**

A `SlugMutationResultDTO` is returned and the current slug is `hello-world`.

**Boundary**

The call passes through `SlugEngine`, the lifecycle service, and package-owned persistence. The Host does not reimplement claiming, uniqueness, or history.

### Purge a Released Binding

Call `purgeBinding()` directly to permanently delete a Binding in `RELEASED` status with no live claims. Pass its `BindingIdentityDTO` as `$binding` and its current revision as the expected `$revision`.

```php
use Maatify\Slug\Lifecycle\Command\PurgeBindingCommand;

$engine->purgeBinding(new PurgeBindingCommand($binding, $revision));
```

When there is no outer transaction, the package opens a purge transaction and then commits or rolls it back. If the Host already has a transaction on the injected PDO, the Host controls its commit or rollback. Locks taken by the purge can remain held until that transaction ends. See [Package Reference §9](../../SLUG_PACKAGE_REFERENCE.md#9-persistence-transactions-and-concurrency).

### Consumer Reads / Resolution

**Input**

The same `ScopeProfileRequestDTO` and the segment `hello-world` after a successful assignment.

**Public Call**

`SlugEngine::resolve(new ResolutionCriteria(...))`; `checkAvailability` and `getCurrent` can be used for the other two reads.

**Result**

A `SlugResolutionDTO` is returned, matching the current claim and pointing to `article/example-1`.

**Boundary**

These reads do not claim ownership. `checkAvailability` is advisory; the actual claim is decided by the mutation path and the unique Registry constraint.

## Management Operational Reads

**Input**

The Binding identity after assignment.

**Public Call**

`SlugEngine::getBinding(new BindingCriteria($bindingIdentity))`.

Use the other management operations with their respective criteria when needed: `getCurrent`, `listAliases`, `getHistory`, `inspectRegistry`, `inspectScope`, `searchBindings`, and `searchRegistry`.

**Result**

`getBinding` returns a `BindingDTO`, and the persisted example shows the current slug as `hello-world`.

**Boundary**

These operational reads are intended for the Host or management tooling and do not redefine the public lifecycle contract. Pagination uses the shared types defined by the Package Reference.

## Scope Discovery

**Input**

`ScopeSearchCriteria` with a `PageRequest`, optionally a literal `namespacePrefix`, and optionally an exact `SlugProfileKey`.

**Public Call**

`SlugEngine::searchScopes(new ScopeSearchCriteria(...))`.

**Result**

The `PageResultDTO<ScopeDTO>` reports `total` for all persisted package Scopes and `filtered` after the optional filters. Namespace prefixes are literal prefixes, not SQL wildcard syntax. Pagination and deterministic sorting are defined by the Package Reference.

**Boundary**

The result contains package-owned `ScopeDTO` snapshots only; the Package does not resolve Host metadata or join Host tables.

## Scope Operational Summary

**Input**

An exact `ScopeProfileRequestDTO` wrapped in `ScopeCriteria`.

**Public Call**

`SlugEngine::getScopeOperationalSummary(new ScopeCriteria($scopeProfile))`.

**Result**

The call returns `ScopeOperationalSummaryDTO` or `null` for a missing Scope. Its Binding and Registry totals obey their documented breakdown invariants, while History is counted from persisted Scope snapshots.

**Boundary**

This is a Slug-domain Scope snapshot, not a generic dashboard or cross-package metrics API.

## Operational History

**Input**

`HistorySearchCriteria` can be package-wide or narrowed to an exact Scope snapshot, with an optional event type and a UTC-normalized half-open window `[fromInclusive, untilExclusive)`.

**Public Call**

`SlugEngine::searchHistory(new HistorySearchCriteria(...))`.

**Result**

The `PageResultDTO<HistoryEventDTO>` uses `total` for the unfiltered package-wide or exact-Scope boundary and `filtered` after event-type/time-window filters. Filtering uses `occurredAt` at microsecond persistence precision, not `originalOccurredAt`; default ordering is deterministic by `occurred_at DESC`, then `id DESC`.

**Boundary**

Exact-Scope attribution uses the persisted History Scope snapshot. A missing requested Scope returns an empty page. The Host does not enumerate Bindings or read package SQL to reconstruct reporting semantics.

## Custom Profile Extension

**Input**

An application-owned `SlugProfileInterface` with a stable versioned key such as `lowercase-words-v1`.

**Public Call**

Register it with `SlugProfileRegistryInterface::register()` on the built-in registry, then pass that registry to `SlugTextServiceFactory::create()` or `SlugEngineFactory::create()` and call the public text/lifecycle API. See [`examples/custom-profile.php`](../../examples/custom-profile.php).

**Result**

The service returns the normal public DTOs and `Slug` values produced by the custom semantic contract.

**Boundary**

The Package Reference is normative for compatibility, duplicate registration, immutable persisted Scope profile configuration, and non-public extension boundaries. This guide intentionally does not duplicate that full contract.

## Transactions / Concurrency Boundary

When the Host has no outer transaction, the package owns the operation transaction and commits or rolls it back according to the contract. When the Host supplies an outer transaction, the package participates in it and uses a savepoint where the capability is required and supported; it does not commit or roll back the outer transaction.

In an exact-claim race, the unique Registry constraint is the final authority and the conflict is mapped to the appropriate semantic exception. Lifecycle mutations use revision/CAS and idempotency when an idempotency key is supplied. The package does not change the global timezone; the example uses a UTC Clock.

The canonical Compose lifecycle is shared by Integration, System, Consumer Harness, and the persisted example; there is no second service lifecycle for the example.

## Errors / Exceptions

The package uses `SlugExceptionInterface` and the domain exception families, with concrete exceptions such as `SlugAlreadyClaimedException`, `SlugRevisionConflictException`, `SlugReservedException`, and `SlugTransactionParticipationException`. The Host must pass these results through the appropriate contract rather than inspecting SQL or catching `Throwable` to create a new contract. See [Package Reference §12](../../SLUG_PACKAGE_REFERENCE.md#12-exception-contract).

## Examples Navigation

- [`examples/canonicalization.php`](../../examples/canonicalization.php) — stateless generation.
- [`examples/custom-profile.php`](../../examples/custom-profile.php) — custom Public profile registration and use.
- [`examples/persisted-lifecycle.php`](../../examples/persisted-lifecycle.php) — assignment, resolution, and management reads on disposable MySQL.

To run all three maintained examples, use the examples gate from a [repository checkout](../../CONTRIBUTING.md#4-examples-smoke-verification):

```bash
bash tools/ci/run-gate.sh examples-smoke
```

The gate runs `canonicalization.php` and `custom-profile.php` directly, then runs `persisted-lifecycle.php` through the canonical Compose lifecycle. The persisted example does not run as a standalone process outside this environment because it requires `SLUG_TEST_DB_*`.

## Further Documentation

- [Package Reference](../../SLUG_PACKAGE_REFERENCE.md) — the normative contract and Public API.
- [CONTRIBUTING.md](../../CONTRIBUTING.md) — development setup and quality gates.
- [CHANGELOG.md](../../CHANGELOG.md) — change history and publication state.
- [SECURITY.md](../../SECURITY.md) — security policy and boundaries.
- [`USAGE_GUIDE_AR.md`](USAGE_GUIDE_AR.md) — non-canonical Arabic translation of this guide.
