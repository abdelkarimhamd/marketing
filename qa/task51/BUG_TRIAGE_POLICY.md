# Bug Triage Policy (Task 51)

## Severity levels

- `P0` Critical outage or data leak
  - Cross-tenant data exposure
  - Message duplication at scale
  - Auth bypass / privilege escalation
- `P1` Major business impact
  - Core journey blocked for a tenant (intake, campaign launch, proposal send, booking)
  - Incorrect billing/profitability calculations
  - High-risk approval bypass
- `P2` Medium impact
  - Partial feature degradation with workaround
  - Non-critical integration instability
- `P3` Minor issue
  - Cosmetic defects
  - Low-risk UX inconsistencies

## Stop-ship conditions

- Any open `P0`
- Any open `P1` without approved exception and rollback plan
- Failed backend regression tests
- Failed integration contract tests
- Failed tenant-isolation/security checks

## Mandatory bug report fields

- Environment (`staging`, `production`, etc.)
- Tenant slug/id
- User role + user id
- Absolute timestamp
- `request_id` (from `X-Request-ID`)
- Reproduction steps (numbered, deterministic)
- Expected vs actual behavior
- API endpoint + payload (sanitized)
- Screenshot or log excerpt

## Rollback strategy requirement

For each risky feature (31–50), define:

- Feature flag key
- Safe default behavior when disabled
- Migration/data rollback notes
- Owner + on-call contact

No release exception is valid without rollback instructions.
