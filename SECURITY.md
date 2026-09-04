# Security

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | yes |

## Reporting a vulnerability

Email **hello@klarsmith.com**. Do not open a public GitHub issue for anything
you believe is exploitable.

Include the plugin version, how to reproduce, and what you think the impact is.
You will get a response within 7 days; we will tell you what we found and when a
fix is expected. Credit in the changelog on request.

There is no bug bounty.

## Scope notes

`/wp-json/ops/v1/metrics` is disabled until `WP_OPS_TOKEN` is set and answers
404 (not 401) to a missing or wrong token. Anonymous `/readyz` returns failing
check names only, never detail. If you find either of those statements to be
untrue, that is a report.
