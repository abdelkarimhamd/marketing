# k6 Load/ Reliability Tests (Task 51)

## Script

- `qa/k6/stability_gate_load.js`

## Scenarios

- `lead_intake_burst`
  - 100 RPS constant arrival rate
  - validates public lead intake under burst traffic
- `campaign_launch_pressure`
  - ramped VUs on campaign launch endpoint
- `inbox_reads`
  - concurrent inbox polling
- `import_run_now_pressure`
  - concurrent schedule run-now triggers

## SLO thresholds

- `http_req_failed < 2%`
- `http_req_duration p95 < 1200ms`
- `http_req_duration p99 < 2500ms`
- `checks > 95%`

## Run example

```bash
k6 run qa/k6/stability_gate_load.js \
  -e BASE_URL=http://127.0.0.1:8000 \
  -e BEARER_TOKEN=your_admin_token \
  -e TENANT_ID=1 \
  -e PUBLIC_TENANT_ID=1 \
  -e CAMPAIGN_ID=1 \
  -e IMPORT_SCHEDULE_ID=1
```

## Notes

- Use staging-like data from `php artisan qa:seed-regression-staging --fresh`.
- For production-like campaign load (`50k+` audience), pair k6 with queue and DB telemetry:
  - queue latency
  - slow query count
  - memory usage
  - failed jobs rate
