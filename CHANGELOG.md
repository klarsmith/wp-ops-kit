# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.7] - 2026-09-07

### Changed
- Directory listing name "Klarsmith Ops Kit" (slug `klarsmith-ops-kit`): the
  wordpress.org review found "Ops Kit" too generic and asked for a distinctive
  brand-first name. Composer and GitHub name stays `klarsmith/wp-ops-kit`.

## [0.1.6] - 2026-09-06

### Fixed
- 0.1.5 accidentally shipped a stray `build3/` directory; removed and ignored.

## [0.1.5] - 2026-09-06

### Changed
- Directory listing name "Ops Kit" (slug `ops-kit`): wordpress.org bans "wp" and
  "wordpress" in new slugs. Built by `bin/build-wporg-zip.sh`; the Composer and
  GitHub name stays `klarsmith/wp-ops-kit`.

## [0.1.4] - 2026-09-06

### Changed
- Display name "Ops Kit for WordPress" (superseded by 0.1.5). `Tested up to: 7.1`.

## [0.1.3] - 2026-09-06

### Changed
- Code conforms to the WordPress Coding Standards (phpcs) and passes PHPStan
  level 6; both run in CI. Two request inputs used for route detection are now
  unslashed and sanitised. No other behaviour change.

### Added
- `examples/`: Kubernetes probes and collector CronJob, stock Prometheus
  (ServiceMonitor, PrometheusRule, plain scrape config), VictoriaMetrics
  (VMServiceScrape, VMRule) and a Grafana dashboard.
- `SECURITY.md`, this changelog, `.gitattributes` export-ignore for tests and CI.

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

[Unreleased]: https://github.com/klarsmith/wp-ops-kit/compare/v0.1.7...HEAD
[0.1.7]: https://github.com/klarsmith/wp-ops-kit/releases/tag/v0.1.7
[0.1.6]: https://github.com/klarsmith/wp-ops-kit/releases/tag/v0.1.6
[0.1.5]: https://github.com/klarsmith/wp-ops-kit/releases/tag/v0.1.5
[0.1.4]: https://github.com/klarsmith/wp-ops-kit/releases/tag/v0.1.4
[0.1.3]: https://github.com/klarsmith/wp-ops-kit/releases/tag/v0.1.3
[0.1.2]: https://github.com/klarsmith/wp-ops-kit/releases/tag/v0.1.2
[0.1.1]: https://github.com/klarsmith/wp-ops-kit/commit/9a9f02c
[0.1.0]: https://github.com/klarsmith/wp-ops-kit/commit/0a506a0
