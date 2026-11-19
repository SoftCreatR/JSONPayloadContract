# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.1] - 2026-11-19

### Changed

- Added PHP 8.2 support, backed by the complete test suite and CI coverage.
- Require JSONPath 2.2.1 or newer for a PHP 8.2-compatible dependency chain.
- Retained constant type documentation while replacing PHP 8.3-only constant declarations.
- Support PHPUnit 11 on PHP 8.2 and PHPUnit 12 on newer runtimes.
- Use PHP CS Fixer exclusively for code style checks.

### Fixed

- Removed the PHP 8.4-only `array_find()` call from the coverage runner.

## [1.0.0] - 2026-10-03

### Added

- Initial PHP 8.3+ implementation.
- Required, optional, and many-value field cardinalities.
- Ordered JSONPath selector fallbacks with match metadata.
- Strict null, scalar, numeric, list, associative-array, collection, and object type constraints with explicit coercion.
- Missing-versus-null semantics and optional defaults.
- Nested contracts, built-in validation rules, custom validators, and transformations.
- Numeric, Unicode string-length, item-count, UUID, HTTP(S) URL, IP address, and date-time constraints with stable violation codes.
- Per-field match-count constraints and secure default limits for JSON bytes, decode depth, and selector fan-out.
- Immutable contract extension and merging with duplicate-name protection and strict resource-limit composition.
- Contract-level cross-field validation after successful normalization.
- Aggregated, structured violations and raw JSON extraction.
- Cached selector parsing and per-extraction selector results for efficient repeated evaluation.
- Append-only violation collection and a reusable benchmark for guarding extraction hot-path performance.
- A mandatory CI gate for 100% class, method, and line coverage.

[1.0.1]: https://github.com/SoftCreatR/JSONPayloadContract/releases/tag/1.0.1
[1.0.0]: https://github.com/SoftCreatR/JSONPayloadContract/releases/tag/1.0.0
