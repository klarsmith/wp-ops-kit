# WP Ops Kit

Makes WordPress legible to Kubernetes and Prometheus: **honest readiness**,
**snapshot-backed metrics**, and **structured JSON logs**.

Status: **0.1.0, pre-release.** Not yet published to wordpress.org or Packagist.

## Why

WordPress has no honest health endpoint. The probe targets everyone reaches for —
`/`, `admin-ajax.php`, a stub `health.php` — return `200` while the database is
unreachable, Redis has vanished, uploads is read-only, or WordPress is serving
the *"database update required"* interstitial instead of the site.

That last one is the dangerous case. When `db_version` in the database is behind
the version compiled into core, WordPress redirects every request to
`upgrade.php`. PHP-FPM is healthy, the homepage returns 200, and the site is
completely down. **Every probe in common use calls that pod ready.** This plugin
does not.

## Design

Three decisions carry the whole thing, and they are the reason this is not just
another WordPress Prometheus exporter.

**Liveness stays dumb.** There is deliberately no liveness endpoint here. If
liveness consulted the database, one database blip would fail liveness on every
pod of every site at once and restart-storm the fleet — turning a recoverable
dependency outage into a self-inflicted one. Liveness answers *"restart me"* and
belongs in a flat PHP file served by the web server. Readiness answers *"take me
out of the pool"*, and that is what this plugin owns.

**Metrics are served from a snapshot, never computed on the scrape.** This is
why the existing exporters get uninstalled. Counting posts, walking the cron
array and summing option sizes on every 30-second scrape will flatten a shared
database once you have more than a couple of sites. Instead:

```
wp ops collect  (CronJob, every 5m)  ──►  transient ──► Redis (shared by all pods)
                                                          │
                          GET /metrics ────────────────────┘   (no DB access at all)
```

The snapshot never expires. If it did, a dead collector would make the site
metrics silently disappear; instead they go stale and `wp_ops_snapshot_age_seconds`
climbs, which you can alert on.

**Per-pod and per-site metrics are separated.** `wp_ops_pod_*` genuinely differs
between replicas (opcache, memory) — keep the pod label. `wp_ops_site_*` comes
from the shared snapshot and is therefore *identical* on every replica; scraping
N pods gives you N duplicate series unless you drop the pod label. See the
relabel config below.

## Install

```bash
composer require klarsmith/wp-ops-kit
wp plugin activate wp-ops-kit
```

As a must-use plugin, add a loader at the root of `mu-plugins/` (WordPress does
not recurse into mu-plugin subdirectories):

```php
<?php // mu-plugins/wp-ops-kit-loader.php
require_once __DIR__ . '/wp-ops-kit/wp-ops-kit.php';
```

### If you run a REST-restricting security plugin or theme

Anything that blocks anonymous REST access blocks `/wp-json/ops/v1/*` too, and
your probes get a 404 or 403 with no explanation. This is common — and it is
worth being precise about, because we found it the hard way on our own fleet
where **two independent lockdowns** were layered on the same site: a security
mu-plugin *and* the theme, each hooking `rest_authentication_errors`.

The plugin therefore defends its own routes. It hooks the same filter at
**priority 1** and returns `true` for its two endpoints only, before any
site-level rule at the default priority 10 gets to run. Nothing else is
affected, and the endpoints still protect themselves: anonymous `readyz`
returns check names without detail, and `metrics` 404s unless a token is
configured *and* presented.

Set `WP_OPS_REST_BYPASS_AUTH=false` to leave your own rules in charge — then
allowlist the namespace yourself. With `imargus/wp-fortress`:

```php
define( 'FORTRESS_REST_ALLOWED_NAMESPACES', ['contact-form-7', 'ops'] );
```

> **Writing your own allow-through? Return `true`, not `null`.** `null` is the
> value the filter chain *starts* with — returning it changes nothing and the
> next filter still blocks the request. We found exactly this no-op guarding a
> health endpoint that had been quietly unreachable for months.

## Configuration

All configuration is read from constants first, then environment — **never from
the database**. Ops behaviour is declared in the deployment, not clicked into
`wp_options`.

| Setting | Default | Purpose |
|---|---|---|
| `WP_OPS_TOKEN` | *(unset)* | Bearer token for `/metrics` and detailed `/readyz`. **Unset disables `/metrics` entirely** — an exporter that fails open is a data leak. |
| `WP_OPS_REQUIRED_PLUGINS` | *(none)* | Comma-separated plugin files that must be active for readiness. |
| `WP_OPS_EXPECT_OBJECT_CACHE` | `false` | Fail readiness when the external object-cache dropin is missing. |
| `WP_OPS_REST_BYPASS_AUTH` | `true` | Assert this plugin's own routes past site-level REST lockdowns (see above). |
| `WP_OPS_LOG_JSON` | `false` | Emit structured JSON logs to stderr. |
| `WP_OPS_SITE_NAME` | host of `home` | `site` label on log records. |

## Endpoints

| Endpoint | Auth | Returns |
|---|---|---|
| `GET /wp-json/ops/v1/readyz` | optional | `200` + `{"status":"ok"}`, or `503` + failing check names. With a token, per-check detail. |
| `GET /wp-json/ops/v1/metrics` | **required** | Prometheus exposition text. `404` without a valid token. |

Unauthenticated `readyz` deliberately returns check *names* but not detail —
`db_version 57155 != core 58975` is as useful to an attacker as to an operator.

### Readiness checks

| Check | Fails when |
|---|---|
| `db` | `SELECT 1` does not come back |
| `db_schema` | `db_version` ≠ core's — **the upgrade-interstitial guard** |
| `object_cache` | Redis roundtrip fails, or dropin missing while `WP_OPS_EXPECT_OBJECT_CACHE` |
| `uploads` | uploads basedir missing or not writable |
| `required_plugins` | any plugin in `WP_OPS_REQUIRED_PLUGINS` is inactive |

## Kubernetes

Readiness on WordPress; liveness left alone.

```yaml
readinessProbe:
  httpGet:
    path: /wp-json/ops/v1/readyz
    port: 8080
  periodSeconds: 10
  failureThreshold: 3
```

The collector — without it, metrics never refresh:

```yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: wp-ops-collect
spec:
  schedule: "*/5 * * * *"
  concurrencyPolicy: Forbid
  jobTemplate:
    spec:
      template:
        spec:
          restartPolicy: OnFailure
          containers:
            - name: collect
              image: <your wp image>
              command: ["wp", "ops", "collect", "--path=/var/www/html/app/public_html"]
```

Scraping — note the relabel that collapses the duplicated site series:

```yaml
apiVersion: operator.victoriametrics.com/v1beta1
kind: VMServiceScrape
spec:
  endpoints:
    - port: http
      path: /wp-json/ops/v1/metrics
      interval: 60s
      bearerTokenSecret:
        name: wp-ops-token
        key: token
      metricRelabelConfigs:
        # wp_ops_site_* is identical on every replica; without this you get one
        # duplicate series per pod, all reporting the same number.
        - sourceLabels: [__name__]
          regex: "wp_ops_site_.*"
          targetLabel: pod
          replacement: ""
```

## Metrics

| Metric | Type | Notes |
|---|---|---|
| `wp_ops_up` | gauge | Always 1 — the exporter answered |
| `wp_ops_build_info` | gauge | Labels: `wp_version`, `php_version`, `plugin_version` |
| `wp_ops_snapshot_age_seconds` | gauge | `-1` if never collected. **Alert on this.** |
| `wp_ops_site_posts` | gauge | Labels: `post_type`, `status`. Zero-valued statuses are omitted, except `publish` — always exported so a drop to zero stays alertable instead of reading as a stale series. |
| `wp_ops_site_users_total` | gauge | |
| `wp_ops_site_cron_events` | gauge | |
| `wp_ops_site_cron_overdue_events` | gauge | |
| `wp_ops_site_cron_oldest_overdue_seconds` | gauge | |
| `wp_ops_site_updates_available` | gauge | Label: `type` = core/plugin/theme |
| `wp_ops_site_plugins_active` | gauge | |
| `wp_ops_site_autoload_options_bytes` | gauge | Autoload bloat — paid on every request |
| `wp_ops_pod_php_memory_peak_bytes` | gauge | |
| `wp_ops_pod_opcache_*` | gauge/counter | Memory, cached scripts, hits, misses |

Update counts are read from WordPress's update **transients**, never by calling
wordpress.org — collecting across a fleet would otherwise mean an outbound
request per site per interval, and eventually a rate limit.

## WP-CLI

```bash
wp ops collect     # refresh the snapshot (what the CronJob runs)
wp ops check       # run readiness checks; non-zero exit on failure
wp ops metrics     # print the exposition text
```

## Development

```bash
composer install
composer test                  # or: vendor/bin/phpunit
```

No PHP on the host? The suite runs anywhere Docker does, which is what CI uses:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 install
docker run --rm -v "$PWD":/app -w /app php:8.5-cli \
  php -d opcache.enable_cli=1 vendor/bin/phpunit
```

### About the tests

The suite runs without a WordPress install: `tests/bootstrap.php` mirrors the
plugin's own require chain and [Brain Monkey](https://github.com/Brain-WP/BrainMonkey)
stands in for the WordPress functions. `tests/HealthyWordPress.php` defines a
site that passes every check, and each test moves exactly one input away from it.

Some of the logic worth pinning lives in private static methods — the exposition
wire format, the cron walk, the token comparison. Rather than widen the public
API for the tests, `TestCase::inScope()` binds a closure into the class scope to
reach them.

**What the unit suite deliberately does not cover**, and what the integration
pass therefore still has to prove on a real site:

| Gap | Why it needs real WordPress |
|-----|-----------------------------|
| `rest_pre_serve_request` ordering | The callback's own behaviour is tested; whether another plugin hooks the filter first and swallows the exposition is not knowable without a live REST stack |
| The `E_ERROR` branch of `Logger::capture_fatal()` | `error_get_last()` only reports a real fatal |
| `Logger::stream()` opening `php://stderr` | Tests redirect the stream to memory to read records back |
| Readiness behind wp-fortress | `ops` must be in `FORTRESS_REST_ALLOWED_NAMESPACES` or every probe 404s — see the warning above |

## Roadmap

- **Phase 2 — drift and awareness:** plugins active in the database but absent
  from `composer.lock`, missing object-cache dropin, core checksum mismatches.
- **Phase 3 — the packaging that makes it adoptable:** Grafana dashboard JSON,
  VMRule/PrometheusRule with runbook annotations, `wp-site` chart integration.

### Before publishing

- [ ] Paste the verbatim GPL-2.0 text into `LICENSE`
- [ ] Add wordpress.org `readme.txt` (stable tag, tested-up-to, screenshots)
- [ ] Work through the integration gaps in the table above on a real site
- [ ] Dogfood across the fleet namespaces
- [ ] Tag `v0.1.0`, submit to wordpress.org, register on Packagist

## Licence

GPL-2.0-or-later. © 2026 klarsmith OÜ.
