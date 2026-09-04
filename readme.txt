=== WP Ops Kit ===
Contributors: klarsmith
Tags: kubernetes, prometheus, monitoring, health-check, logging
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Makes WordPress legible to Kubernetes and Prometheus: honest readiness, snapshot-backed metrics, structured JSON logs.

== Description ==

WordPress has no honest health endpoint. The front page, `admin-ajax.php` and the usual
`health.php` stubs all return 200 while the database is down, the object cache is gone, or
WordPress is serving its "database update required" interstitial. Every probe in common
use calls that pod ready.

WP Ops Kit gives a containerised WordPress site three things operators actually need:

* **Honest readiness** — `GET /wp-json/ops/v1/readyz` returns 200 only when the database
  answers, the schema matches the running core version, the object cache round-trips, and
  the uploads directory is writable. Anything else is a 503 with the failing check names.
  Liveness is deliberately left alone: a database outage must drain pods, never restart
  them.
* **Prometheus metrics** — `GET /wp-json/ops/v1/metrics` serves the text exposition
  format from a snapshot that `wp ops collect` writes to the object cache on a schedule.
  Nothing expensive runs on scrape. `wp_ops_snapshot_age_seconds` tells you when the
  collector has stopped. Pod-level series (`wp_ops_pod_*`) and site-level series
  (`wp_ops_site_*`) are split so replicas do not duplicate site numbers.
* **Structured JSON logs** — with `WP_OPS_LOG_JSON=true`, PHP errors and fatals go to
  stderr as one JSON object per line, ready for a log pipeline.

The plugin fails closed. `/metrics` is disabled until a token is configured, and the
anonymous readiness response names failing checks without leaking detail.

Configuration is by environment variable, which is how containers are configured:

* `WP_OPS_TOKEN` — bearer token for `/metrics` (`Authorization: Bearer` or
  `X-Ops-Token`). Unset disables the endpoint.
* `WP_OPS_SITE_NAME` — label attached to every series.
* `WP_OPS_EXPECT_OBJECT_CACHE` — fail readiness when no external object cache is active.
* `WP_OPS_REQUIRED_PLUGINS` — comma-separated plugin files (`dir/plugin.php`) that
  must be active for readiness.
* `WP_OPS_REST_BYPASS_AUTH` — set to `false` to stop the plugin allowing anonymous
  access to its own REST namespace.
* `WP_OPS_LOG_JSON` — set to `true` to emit JSON log lines on stderr.

WP-CLI commands: `wp ops check` (exit 1 on any failing check), `wp ops collect`
(refresh the snapshot), `wp ops metrics` (print the exposition locally).

Source, issue tracker and Kubernetes manifests for probes, the collector CronJob and
scraping live at https://github.com/klarsmith/wp-ops-kit.

== Installation ==

1. Install with Composer (`composer require klarsmith/wp-ops-kit`) or upload the
   `wp-ops-kit` directory to `wp-content/plugins/`.
2. Activate the plugin.
3. Point your readiness probe at `/wp-json/ops/v1/readyz`.
4. Run `wp ops collect` on a schedule (a five-minute CronJob is the reference setup).
5. Set `WP_OPS_TOKEN` and scrape `/wp-json/ops/v1/metrics` with that bearer token.

If a security plugin blocks anonymous REST requests, the plugin allows its own `ops/v1`
namespace through at priority 1 unless `WP_OPS_REST_BYPASS_AUTH=false`. Add `ops` to the
security plugin's allowlist as well where it has one.

== Frequently Asked Questions ==

= Why not use the front page as the readiness probe? =

Because it returns 200 while the site is broken. The database-upgrade interstitial, a
missing object cache and a read-only uploads volume all serve a 200 front page.

= Why does readiness not cover liveness too? =

If liveness consulted the database, one database blip would fail liveness on every pod
of every site at once and restart-storm the fleet. Readiness drains; liveness restarts.
Only readiness should depend on WordPress.

= Why is /metrics computed from a snapshot? =

Walking the cron array and summing autoloaded option sizes on every scrape would load a
shared database for no benefit. The collector does the work once; scrapes read the
result. If the collector dies the snapshot age climbs, which is alertable.

= Does it work behind WPML or a language prefix? =

Yes. Route detection uses the REST route query variable with a request-URI fallback, so
`/en/wp-json/ops/v1/readyz` works the same as `/wp-json/ops/v1/readyz`.

== Changelog ==

= 0.1.2 =
* `/metrics` no longer appends its exposition to a response another handler has already
  served.
* Zero-valued post statuses are dropped at collection time; `publish` is always exported
  so a drop to zero stays alertable.

= 0.1.1 =
* Allow the plugin's own `ops/v1` REST namespace through site-level anonymous-REST
  lockdowns (hooked at priority 1, never overriding an earlier decision).
  Escape hatch: `WP_OPS_REST_BYPASS_AUTH=false`.

= 0.1.0 =
* Initial release: readiness endpoint, snapshot-backed Prometheus metrics, JSON logging,
  `wp ops check|collect|metrics` commands.

== Upgrade Notice ==

= 0.1.2 =
First public release. No configuration changes required from 0.1.x pre-releases.
