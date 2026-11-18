<div align="center">

# Maatify Slug

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://www.maatify.dev/company-profile/maatify_logo_white.svg">
  <source media="(prefers-color-scheme: light)" srcset="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
  <img alt="Maatify.dev" src="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
</picture>

[![Status](https://img.shields.io/badge/Status-Release%20Candidate-orange.svg)](#publication-state)
[![Artifact target](https://img.shields.io/badge/Artifact%20target-1.0.0--rc.3-blue.svg)](#publication-state)
[![Published at qualification](https://img.shields.io/badge/Published%20at%20qualification-1.0.0--rc.2-blue.svg)](https://packagist.org/packages/maatify/php-slug#v1.0.0-rc.2)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.4.27%20%3C8.5.0%20%7C%7C%20%3E%3D8.5.12%20%3C9.0.0-8892BF)](composer.json)
[![License](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHPStan](https://img.shields.io/badge/PHPStan-Max-brightgreen.svg)](#quality-status)

[![Packagist](https://img.shields.io/badge/Packagist-Distribution-blue.svg)](https://packagist.org/packages/maatify/php-slug)
[![Monthly Downloads](https://img.shields.io/packagist/dm/maatify/php-slug.svg)](https://packagist.org/packages/maatify/php-slug)
[![Total Downloads](https://img.shields.io/packagist/dt/maatify/php-slug.svg)](https://packagist.org/packages/maatify/php-slug)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify-ltd)
[![Install published RC.2](https://img.shields.io/badge/Install%20published%20RC.2-1.0.0--rc.2%40RC-blue.svg)](#published-rc2)
[![Install after publication](https://img.shields.io/badge/Install%20after%20publication-1.0.0--rc.3%40RC-blue.svg)](#installation)

[![Usage Guide](https://img.shields.io/badge/Usage-Guide-blue.svg)](docs/guides/USAGE_GUIDE.md)
[![Examples](https://img.shields.io/badge/Examples-View-blue.svg)](examples/)
[![Package Reference](https://img.shields.io/badge/Reference-Read-blue.svg)](SLUG_PACKAGE_REFERENCE.md)
[![Changelog](https://img.shields.io/badge/Changelog-View-blue.svg)](CHANGELOG.md)
[![Security Policy](https://img.shields.io/badge/Security-Policy-blue.svg)](SECURITY.md)
[![Contributing Guide](https://img.shields.io/badge/Contributing-Guide-blue.svg)](CONTRIBUTING.md)

An independent PHP slug lifecycle engine with clear boundaries between the slug domain, the Host, URLs, HTTP, and SEO.

</div>

---

## Publication State

**Release target:** `1.0.0-rc.3`, a Pre-Stable Release Candidate.

At qualification, `1.0.0-rc.3` is unpublished and `1.0.0-rc.2` is available through Packagist. No Stable support line is established.

The current public contract is owned by [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md). Execution evidence is maintained in GitHub PR and CI history rather than duplicated in this consumer-facing README.

The requirements and examples below are for RC.3.

## Package Summary

`maatify/php-slug` is a standalone PHP library for slug generation, canonicalization, scoped ownership, lifecycle management, aliases, history, resolution, and package-owned PDO MySQL persistence.

## Key Features

- **Slug Generation & Canonicalization:** Consistent generation and canonicalization through versioned built-in profiles.
- **Ownership & Lifecycle:** Exact claiming, generated allocation, release, scope transition, atomic transfer, and adoption.
- **Aliases & History:** Active and retired aliases with immutable lifecycle history.
- **Persistence & Concurrency:** PDO MySQL-compatible persistence with transactions, CAS, and concurrency guarantees.
- **Resolution & Management:** Resolution, availability, Scope discovery, Scope operational summaries, operational History windows, and management reads with shared pagination.
- **Public Extension:** Versioned custom Slug profiles through the public registry path, plus Host-owned reserved-slug policy injection.

## Requirements

| Requirement | Value |
|---|---|
| PHP (Composer) | `>=8.4.27 <8.5.0 \|\| >=8.5.12 <9.0.0` |
| Database | PDO MySQL; `mysql:8.0.36` is the CI reproducibility target |
| Extensions | `ext-filter`, `ext-intl`, `ext-mbstring`, `ext-pdo`, `ext-pdo_mysql` |
| Direct packages | `maatify/exceptions ^1.0`, `maatify/shared-common ^1.0`, `maatify/php-persistence 1.0.0-rc.2` (temporary RC dependency) |

CI verifies PHP 8.4 and 8.5. Composer also admits newer PHP 8 minors within this range. Each newer minor needs its own security and compatibility evidence before it can be listed as supported.

Built-in profiles require the normalization and transliteration capabilities provided by `ext-intl`; the package verifies those capabilities at runtime. No exact ICU or Unicode version is required from the consumer.

## Installation

Once RC.3 is published to Packagist, install the exact target and its Persistence dependency using the following command. Keep your project's `minimum-stability` set to `stable`; the two `@RC` allowances apply to these packages.

```bash
composer require maatify/php-slug:1.0.0-rc.3@RC maatify/php-persistence:1.0.0-rc.2@RC
```

**Upgrading from RC.2.** Stop Slug traffic and apply the additive `003_registry_lock_projection.sql` migration before enabling the RC.3 persisted runtime. Follow the [schema upgrade guide](schema/mysql/README.md#installation-and-upgrade) for the backfill and verification steps. Update any pagination imports from the retired `maatify/persistence` dependency to the [types from `maatify/php-persistence`](SLUG_PACKAGE_REFERENCE.md#10-management-resolution-and-pagination).

## Published RC.2

`1.0.0-rc.2` was the published version at RC.3 qualification. To install that exact earlier version:

```bash
composer require maatify/php-slug:1.0.0-rc.2@RC
```

Use the [versioned RC.2 usage guide](https://github.com/Maatify-ltd/php-slug/blob/v1.0.0-rc.2/docs/guides/USAGE_GUIDE.md).

## Quick Usage

The stateless path does not require a database or Host framework:

```php
$profiles = SlugProfileRegistryFactory::createBuiltIn();
$text = SlugTextServiceFactory::create($profiles);
$slug = $text->generateFromSource(
    new SlugProfileKey('ascii-v1'),
    'Hello, World!',
);
// $slug->slug->value === 'hello-world'
```

For the persisted path, the Host injects a `PDO` connection, a `ReservedSlugPolicyInterface`, and a `ClockInterface` into `SlugEngineFactory::create(...)`. See [`docs/guides/USAGE_GUIDE.md`](docs/guides/USAGE_GUIDE.md) for the complete workflow.

## Public Runtime API

The public Runtime provides actual paths for:

- canonicalization through `SlugTextServiceInterface`, `generateFromSource`, `canonicalizeClaim`, and `canonicalizeLookup`;
- lifecycle ownership through `SlugEngine` and `SlugLifecycleServiceInterface`, including `assignExact`;
- consumer reads through `checkAvailability`, `getCurrent`, and `resolve`; and
- management reads through `SlugManagementQueryInterface`, including `searchScopes`, `getScopeOperationalSummary`, and `searchHistory`.

This overview does not replace the complete inventory and contracts in [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md).

## Examples

- [`examples/canonicalization.php`](examples/canonicalization.php): a stateless generation and canonicalization example.
- [`examples/custom-profile.php`](examples/custom-profile.php): a stateless custom profile registration and usage example.
- [`examples/persisted-lifecycle.php`](examples/persisted-lifecycle.php): a persisted MySQL lifecycle example using the canonical Compose flow.

The canonicalization and custom-profile examples run without Docker. Run the persisted example from a repository checkout using the [examples smoke verification](CONTRIBUTING.md#4-examples-smoke-verification).

## Documentation

- [`docs/guides/USAGE_GUIDE.md`](docs/guides/USAGE_GUIDE.md) — consumer usage and integration.
- [`examples/`](examples/) — runnable consumer examples.
- [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md) — the current public contract and Public API reference.
- [`CHANGELOG.md`](CHANGELOG.md) — change history and publication state.
- [`SECURITY.md`](SECURITY.md) — security policy and reporting route.
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — development setup and verification commands.
- [`README_AR.md`](README_AR.md) — non-canonical Arabic translation of this README.

## Transactions and Concurrency

On the persisted path, the package owns the transaction when the Host has no outer transaction and uses a savepoint when participating in an outer transaction where the capability is supported. Exact claims rely on the unique Registry constraint as the final race authority and use lifecycle mutation CAS and idempotency according to the contract. See [`SLUG_PACKAGE_REFERENCE.md`](SLUG_PACKAGE_REFERENCE.md) for the normative details.

## Security and Trust Boundaries

The Host owns the connection, PDO configuration, entity existence, routing, HTTP, SEO, and authorization. The package does not create hidden connections or use Host foreign keys or joins. For vulnerability reports, see [Security Policy](SECURITY.md); do not disclose private vulnerability details through GitHub Issues.

## Exceptions and Error Propagation

Package-defined semantic and domain failures use the `SlugExceptionInterface` / `SlugDomainExceptionInterface` hierarchy described in the [Package Reference](SLUG_PACKAGE_REFERENCE.md#12-exception-contract). External infrastructure `Throwable` instances, including PDO failures outside the documented semantic conversions, may propagate unchanged according to the current contract.

## Quality Status

The package is maintained behind the repository's configured CI and quality gates. Current execution evidence is maintained in GitHub PR and CI history.

## Development and Testing

For repository setup, tests, and example gates, see [`CONTRIBUTING.md`](CONTRIBUTING.md). The persisted example uses the checkout’s disposable MySQL service.

## License

Licensed under the [MIT License](LICENSE).

## 👤 Author

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
[https://www.maatify.dev](https://www.maatify.dev)

---

<div align="center">

[Built with ❤️ by Maatify.dev — Unified Ecosystem for Modern PHP Libraries](https://www.maatify.dev)

</div>
