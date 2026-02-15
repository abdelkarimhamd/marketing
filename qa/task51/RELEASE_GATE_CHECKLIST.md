# Release Gate Checklist (Features 31–50)

## 1) Regression matrix readiness

- [ ] `qa/task51/regression_matrix_31_50.json` validated
- [ ] Every feature 31–50 has >= 3 test cases
- [ ] Happy-path + 2 edge cases documented

## 2) Controlled data

- [ ] `php artisan qa:seed-regression-staging --fresh` completed
- [ ] Counts verified: 3 tenants / 10 users each / 5k leads each / 5 brands total
- [ ] Re-run produces predictable counts (no drift)

## 3) Backend regression

- [ ] Tenant isolation regression tests pass
- [ ] RBAC/permission regression tests pass
- [ ] Import/attachment/proposal/booking/billing tests pass
- [ ] Contract tests pass (webhook verification + payload mapping)

## 4) Frontend E2E

- [ ] E2E suite is green for required flows
- [ ] Customer success console flow validated
- [ ] Workspace analytics flow validated

## 5) Load/reliability

- [ ] k6 load script executed against staging
- [ ] Queue latency within SLO
- [ ] P95 latency within SLO
- [ ] Job failure rate within SLO

## 6) Security regression

- [ ] Attachment/export access control tests pass
- [ ] Public form rate-limits verified
- [ ] No cross-tenant leakage detected
- [ ] Injection/URL import protections verified

## 7) Bug triage status

- [ ] Open `P0` count = 0
- [ ] Open `P1` count = 0 (or documented exception)
- [ ] Rollback plan exists per high-risk feature

## 8) Final gate decision

- [ ] Backend tests pass
- [ ] E2E tests pass
- [ ] Contract tests pass
- [ ] Load SLO checks pass
- [ ] No blocking incidents remain

Only after all items are complete should Tasks 31–50 be marked stable.
