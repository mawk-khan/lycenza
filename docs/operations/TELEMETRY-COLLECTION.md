# Runbook: telemetry collection failing (OBS-26)

ADR 0051 §9, §16. Telemetry is best effort: its failure never stops the
ERP, so it must be noticed separately.

- **Scrape target down:** the collector cannot reach the web role's private
  metrics listener (port 9102, `/metrics`, bearer `METRICS_SCRAPE_TOKEN`).
  Check the collector's network path to the private port (the public TLS
  proxy never routes it), that the collector presents the current token
  (a rotated token must be updated on both sides), and that a web replica
  is running. A 401 with an empty body is a token mismatch; a 404 means the
  request did not arrive on the private listener.
- **`lycenza_metrics_collection_errors_total` increasing:** one collector
  (`component` label) could not compute; the rest of the scrape still
  rendered. `recorder` = the Redis metrics store was unavailable (see
  OBS-03); `evidence` = the deployment evidence file is missing or invalid
  (check its schema, `docs/operations/BACKUP-AND-RESTORE.md`); others = a
  database read failed (see OBS-02).
- Logs are JSON on stderr, collected by the container runtime; the
  application never ships logs itself.

While telemetry is down, `console platform:operations-status` is still the
authoritative operator view.
