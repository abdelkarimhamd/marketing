# Task 51 Stability Gate

This folder contains the regression QA and release-gating assets for features **31–50**.

## Included artifacts

- `regression_matrix_31_50.json`
  - Coverage map for features 31–50.
  - Includes endpoints, UI modules, jobs/integrations, edge cases, DB mutations, and minimum test cases.
- `BUG_TRIAGE_POLICY.md`
  - Severity model (P0–P3), stop-ship rules, and bug report requirements.
- `RELEASE_GATE_CHECKLIST.md`
  - Exact gate criteria and execution order.

## Controlled staging data

Use the deterministic seed command:

```bash
php artisan qa:seed-regression-staging --fresh
```

Dataset shape:

- 3 tenants: Clinic, RealEstate, Restaurant
- 10 users per tenant with assignment availability schedules
- 5 brands total distributed across tenants
- 5,000 leads per tenant (duplicates + mixed consent + mixed locales)
- Cross-channel templates/campaigns + import presets/schedules

## Regression tests (backend)

Run targeted stability gate suites:

```bash
php artisan test --filter=RegressionMatrixCoverageTest
php artisan test --filter=TenantIsolationModels31To50Test
php artisan test --filter=IntegrationContractRegressionTest
php artisan test --filter=SecurityRegressionGateTest
php artisan test --filter=TenantConsoleDiagnosticsTest
php artisan test --filter=WorkspaceAnalyticsReportingTest
```

## Frontend E2E

Playwright specs are under:

- `admin/tests/e2e/stability-gate.spec.js`

Run with:

```bash
cd admin
npm run test:e2e
```

## Load testing

k6 assets:

- `qa/k6/stability_gate_load.js`
- `qa/k6/README.md`

## CI gate

GitHub Actions workflow:

- `.github/workflows/stability-gate.yml`

This workflow enforces:

- backend regression tests
- integration contract tests
- optional E2E + load stages
- final stop-ship check for open P0/P1 counts
