# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.2] - 2026-09-04

### Fixed
- `/metrics` no longer appends its exposition to a response another handler has
  already served.

### Changed
- Zero-valued post statuses are dropped at collection time; `publish` is always
  exported so a drop to zero stays alertable.

## [0.1.1] - 2026-09-04

### Added
- The plugin's own `ops/v1` REST namespace is allowed through site-level
  anonymous-REST lockdowns (hooked at priority 1, never overriding an earlier
  decision). Escape hatch: `WP_OPS_REST_BYPASS_AUTH=false`.

## [0.1.0] - 2026-09-03

### Added
- Initial release: readiness endpoint, snapshot-backed Prometheus metrics, JSON
  logging, `wp ops check|collect|metrics` commands.

[Unreleased]: https://github.com/klarsmith/wp-ops-kit/compare/v0.1.2...HEAD
[0.1.2]: https://github.com/klarsmith/wp-ops-kit/releases/tag/v0.1.2
[0.1.1]: https://github.com/klarsmith/wp-ops-kit/commit/9a9f02c
[0.1.0]: https://github.com/klarsmith/wp-ops-kit/commit/0a506a0
