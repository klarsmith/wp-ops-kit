# Examples

Copy-paste manifests for running wp-ops-kit on Kubernetes. Everything here is the
generalised version of what we run on our own fleet — names and image references
are placeholders, the shapes are not.

| Directory | What is in it |
|---|---|
| `kubernetes/` | `wp-ops-token-secret.yaml` (the `/metrics` bearer token), `wp-ops-collect-cronjob.yaml` (the 5-minute collector), `wordpress-deployment-probes.yaml` (a php-fpm + nginx Deployment showing where the readiness probe goes, liveness left dumb, `WP_OPS_*` env from a ConfigMap) |
| `prometheus/` | Stock Prometheus path. `servicemonitor.yaml` (prometheus-operator / kube-prometheus-stack), `prometheus-scrape-config.yaml` (hand-run Prometheus, `scrape_configs` entry), `alert-rules.yaml` (`PrometheusRule` CRD), `alert-rules-plain.yml` (plain rule file) |
| `victoriametrics/` | VictoriaMetrics operator path. `vmservicescrape.yaml`, `vmrule.yaml`, and `vmalert-rules.yml` (same rules as `../prometheus/alert-rules-plain.yml`, for a hand-run vmalert) |
| `grafana/` | `wp-ops-kit.json` — dashboard (import via Dashboards → New → Import, or file-provision it). Overview stats, site health, per-pod opcache and PHP memory. Filter by `namespace`; datasource is asked for on import |

## Order

Each step is useful on its own; each later one assumes the earlier ones.

1. **Token** — `kubernetes/wp-ops-token-secret.yaml`, in the site's namespace.
   Until it exists `/metrics` answers 404.
2. **Collector** — `kubernetes/wp-ops-collect-cronjob.yaml`. Without it the
   snapshot is never written and `wp_ops_snapshot_age_seconds` stays at `-1`.
3. **Readiness probe** — the `readinessProbe` block from
   `kubernetes/wordpress-deployment-probes.yaml` on the container that fronts
   WordPress. Leave liveness on a static nginx/fpm check.
4. **Scrape** — one of `prometheus/servicemonitor.yaml`,
   `prometheus/prometheus-scrape-config.yaml` or `victoriametrics/vmservicescrape.yaml`.
   Keep the `wp_ops_site_.*` → drop `pod` relabel; every replica serves the same
   site snapshot.
5. **Alerts** — the matching rule file. Four rules: snapshot stale, snapshot
   never collected, scrape down, site not ready.
6. **Dashboard** — `grafana/wp-ops-kit.json`.

Conventions shared by all files: Service `wordpress` with port `http` and label
`app: wordpress`; Secret `wp-ops-token` with key `WP_OPS_TOKEN` (one Secret feeds
both the pod env and the scraper); ConfigMap `wordpress-config`; the scraper's
`namespace` label is the site identity — the series carry no `site` label.
