# Observability Debug-First Standard (31–50)

## Required structured context

All API requests should emit context keys:

- `request_id`
- `tenant_id`
- `user_id`
- `feature`
- `provider` (if integration/webhook)
- `message_id` (if messaging/webhook)
- `method`
- `path`

`X-Request-ID` must be returned in every API response.

## Failure viewer sources (admin/support)

- Webhook failures: `Webhooks Inbox` (`/api/admin/webhooks-inbox`)
- Customer success diagnostics: `Tenant Console` (`/api/admin/tenant-console`)
- Import failures: lead import schedule `last_status` + `last_error`
- Send failures: campaign/message status + activity logs
- Queue failures: Laravel `failed_jobs` + logs

## Repro target

Any incident should be reproducible in under 10 minutes using:

1. `request_id`
2. tenant id/slug
3. user id/role
4. endpoint + payload
5. related entity id (lead/campaign/message/import/proposal)
