# Marketion User Guide (Demo Credentials + Test Data)

## 1. Start the Project

### Backend (Laravel)

First-time setup only (keeps existing data on next runs):
```powershell
cd c:\xampp\htdocs\marketion\backend
php artisan migrate --seed
```

Terminal A (API server):
```powershell
cd c:\xampp\htdocs\marketion\backend
php artisan serve --host=127.0.0.1 --port=8000
```

Terminal B (queue worker - required for campaign sending):
```powershell
cd c:\xampp\htdocs\marketion\backend
php artisan queue:work
```

Do not run `php artisan migrate:fresh --seed` unless you intentionally want to delete all inserted data.

### Admin (React)
```powershell
cd c:\xampp\htdocs\marketion\admin
cmd /c npm run dev -- --host 127.0.0.1 --port 5173
```

Admin UI URL:
- `http://127.0.0.1:5173`

API base URL:
- `http://127.0.0.1:8000`

## 2. Full Seeded Credentials (All Roles)

All seeded users use:
- Password: `password`

`device_name` can be any string. Suggested values below help identify tokens in `personal_access_tokens`.

| Role persona | Login email | Stored base role | Seeded tenant role template | Password | Suggested `device_name` |
| --- | --- | --- | --- | --- | --- |
| Super Admin | `super.admin@demo.test` | `super_admin` | n/a | `password` | `super-admin-ui` |
| Tenant Admin | `tenant.admin@demo.test` | `tenant_admin` | `template-admin` | `password` | `tenant-admin-ui` |
| Sales | `sales@demo.test` | `sales` | `template-sales` | `password` | `sales-ui` |
| Manager | `manager@demo.test` | `sales` | `template-manager` | `password` | `manager-ui` |
| Marketing | `marketing@demo.test` | `sales` | `template-marketing` | `password` | `marketing-ui` |

Seeded tenant:
- Name: `Demo Tenant`
- Slug: `demo-tenant`
- Domain: `demo.localhost`
- Admin domain: `admin.demo.localhost`

Seeded public API key:
- `demo-public-key`

## 3. First Login Steps (Recommended Flow)

### Step 1: Login and get token
`POST /api/auth/login`

```json
{
  "email": "super.admin@demo.test",
  "password": "password",
  "device_name": "super-admin-ui"
}
```

Save the `token` from response and use:
- `Authorization: Bearer <TOKEN>`

### Step 2: Check authenticated user
`GET /api/auth/me`

### Step 3: Load tenants (Super Admin)
`GET /api/admin/tenants`

Use returned tenant `id` for tenant switch context.

### Step 4: Switch tenant context
Send one of these on admin API calls:
1. Header: `X-Tenant-ID: <tenant_id>`
2. Query: `?tenant_id=<tenant_id>`

Example:
- `GET /api/admin/dashboard?tenant_id=1`

### Step 5: Open dashboard
`GET /api/admin/dashboard`

Important:
- In all payload examples below, replace `tenant_id: 1` with your actual tenant id from `GET /api/admin/tenants`.

## 3A. Admin UI (No JSON) - Tenant Creation + Role-Based Sidebar

If you use the React Admin UI (`http://127.0.0.1:5173`), you can do this without API payloads:

1. Login as `super.admin@demo.test`.
2. In the top bar, open **New Tenant**:
- Desktop: click `New Tenant` button.
- Mobile: click the `+` icon.
3. Fill simple fields (Name, optional Slug/Domain, Timezone, Locale, Currency, Active) and click `Create Tenant`.
4. Use the tenant dropdown in top bar to switch to the tenant you want.
5. Open `Roles` module and use `New User` to create tenant users (name/email/password + access template).
6. Sidebar tabs are now role/permission-based:
- Users only see modules allowed by their permission matrix.
- `super_admin` and `tenant_admin` can see all modules for their context.
- Roles like `sales`/`manager`/`marketing` do not see restricted modules (for example Roles, Billing, Settings when not allowed).

## 4. Generate Test Data After First Login

Use `tenant.admin@demo.test` or super-admin with tenant context.

### 4.1 Create a lead from public endpoint
`POST /api/public/leads`

Headers:
- `X-Tenant-Slug: demo-tenant`

Body:
```json
{
  "first_name": "Ali",
  "last_name": "Khan",
  "email": "ali.khan@example.test",
  "phone": "+15550001111",
  "city": "Riyadh",
  "interest": "crm",
  "service": "implementation",
  "source": "website",
  "tags": ["website", "demo"],
  "email_consent": true
}
```

### 4.2 Import multiple leads
`POST /api/admin/leads/import`

```json
{
  "tenant_id": 1,
  "auto_assign": true,
  "leads": [
    {
      "first_name": "Sara",
      "email": "sara@example.test",
      "city": "Jeddah",
      "interest": "solar",
      "service": "consulting",
      "tags": ["imported", "vip"]
    },
    {
      "first_name": "Omar",
      "phone": "+15550002222",
      "city": "Dammam",
      "interest": "crm",
      "service": "support",
      "tags": ["imported"]
    }
  ]
}
```

### 4.3 Create a segment
`POST /api/admin/segments`

```json
{
  "tenant_id": 1,
  "name": "Riyadh CRM Leads",
  "rules_json": {
    "operator": "AND",
    "rules": [
      { "field": "city", "operator": "equals", "value": "Riyadh" },
      { "field": "interest", "operator": "equals", "value": "crm" }
    ]
  }
}
```

### 4.4 Create templates

Email template:
`POST /api/admin/templates`
```json
{
  "tenant_id": 1,
  "name": "Welcome Email",
  "channel": "email",
  "subject": "Welcome {{first_name}}",
  "html": "<p>Hello {{first_name}}, welcome to Marketion.</p><a href=\"https://example.com/offer\">View offer</a>"
}
```

SMS template:
`POST /api/admin/templates`
```json
{
  "tenant_id": 1,
  "name": "SMS Follow-up",
  "channel": "sms",
  "text": "Hi {{first_name}}, we will call you shortly."
}
```

WhatsApp template:
`POST /api/admin/templates`
```json
{
  "tenant_id": 1,
  "name": "WA Welcome",
  "channel": "whatsapp",
  "whatsapp_template_name": "welcome_template",
  "whatsapp_variables": {
    "name": "{{first_name}}",
    "service": "{{service}}"
  }
}
```

### 4.5 Create and launch a campaign

Create:
`POST /api/admin/campaigns`
```json
{
  "tenant_id": 1,
  "name": "Campaign A",
  "segment_id": 1,
  "template_id": 1,
  "campaign_type": "broadcast"
}
```

Launch:
`POST /api/admin/campaigns/1/launch`

Check logs:
- `GET /api/admin/campaigns/1/logs`
- If you only see `campaign.launch.requested` and no `campaign.launch.dispatched` / `campaign.message.sent`, your queue worker is not running.
- If `EMAIL_PROVIDER=mock` (default), no real inbox email is sent. You will only see sent status/activity logs.

### 4.6 Dashboard and activity checks

Leads list:
- `GET /api/admin/leads`

Lead activities:
- `GET /api/admin/leads/1/activities`

Dashboard:
- `GET /api/admin/dashboard`

### 4.7 Settings and API key test data

Update settings:
`PUT /api/admin/settings`
```json
{
  "tenant_id": 1,
  "domain": "demo.localhost",
  "providers": {
    "email": "mock",
    "sms": "mock",
    "whatsapp": "mock"
  },
  "domains": ["demo.localhost", "www.demo.localhost"],
  "slack": {
    "enabled": false,
    "channel": "#crm-alerts",
    "webhook_url": ""
  }
}
```

Email sending mode options (Settings -> Branding & Providers):
- `platform domain`: send from your platform default sender (`MAIL_FROM_ADDRESS`).
- `tenant domain`: send from tenant sender profile.
  - Set `From Address` + `From Name`.
  - Optional: enable `Use tenant SMTP credentials` and enter SMTP host/port/user/password/encryption for that tenant only.
  - If tenant SMTP password is already saved, leave password field blank to keep existing password.

Domain verification note:
- In real domains, your custom host must CNAME to `TENANCY_CNAME_TARGET` (or kind-specific target).
- If provider rejects your target (for example `.local`), set a public value in `.env` (`TENANCY_CNAME_TARGET`) or enter `CNAME Target (optional override)` in Settings -> Domains when adding the domain.
- Current local setup targets:
  - `TENANCY_ADMIN_CNAME_TARGET=smartcedra.com`
  - `TENANCY_LANDING_CNAME_TARGET=smartcedra.online`
- Current Smart Cedra DNS mapping:
  - `admin.smartcedra.online` CNAME `smartcedra.com`
  - `landing.smartcedra.online` CNAME `smartcedra.online`
- In local/testing, suffixes like `.localhost`, `.test`, `.local` can verify without public DNS when `TENANCY_VERIFICATION_ALLOW_LOCAL_BYPASS=true`.

Create API key:
`POST /api/admin/api-keys`
```json
{
  "tenant_id": 1,
  "name": "Test Intake Key",
  "abilities": ["public:leads:write"]
}
```

## 5. Webhook and Tracking Test

### 5.1 Simulate email webhook status update
`POST /api/webhooks/email/mock`

```json
{
  "provider_message_id": "mock-email-123",
  "status": "delivered"
}
```

### 5.2 View webhook inbox
- `GET /api/admin/webhooks-inbox`
- `GET /api/admin/webhooks-inbox/{id}`

### 5.3 Email tracking
Sent emails include:
1. Open pixel URL: `/track/open/{token}`
2. Click redirect URL: `/track/click/{token}`

When triggered, message fields update:
- `opened_at`
- `clicked_at`
- `status` (`opened` / `clicked`)

## 6. Role-based Quick Usage

### 6.1 Role behavior summary

1. `super_admin`
- Can access all tenants and switch tenant context.
- Can use all admin modules and platform settings.

2. `tenant_admin`
- Full access inside its own tenant.
- Cannot manage other tenants.

3. `sales`
- Daily execution role (leads, inbox, campaigns/templates/segments viewing and basic operations).
- No access to roles, billing, settings.

4. `manager`
- Team-level operations with exports/workflows.
- No access to roles, billing, settings.

5. `marketing`
- Campaign/template/segment heavy workflows plus AI and webhook visibility.
- No access to roles, billing, settings.

### 6.2 Verified endpoint matrix (real run)

Checked on `2026-02-12` using seeded users and `GET` calls.

| Endpoint | super_admin | tenant_admin | sales | manager | marketing |
| --- | --- | --- | --- | --- | --- |
| `/api/auth/me` | 200 | 200 | 200 | 200 | 200 |
| `/api/admin/dashboard` | 200 | 200 | 200 | 200 | 200 |
| `/api/admin/leads` | 200 | 200 | 200 | 200 | 200 |
| `/api/admin/segments` | 200 | 200 | 200 | 200 | 200 |
| `/api/admin/templates` | 200 | 200 | 200 | 200 | 200 |
| `/api/admin/campaigns` | 200 | 200 | 200 | 200 | 200 |
| `/api/admin/inbox` | 200 | 200 | 200 | 200 | 200 |
| `/api/admin/settings` | 200 | 200 | 403 | 403 | 403 |
| `/api/admin/webhooks-inbox` | 200 | 200 | 403 | 403 | 200 |
| `/api/admin/roles` | 200 | 200 | 403 | 403 | 403 |
| `/api/admin/billing/plans` | 200 | 200 | 403 | 403 | 403 |
| `/api/admin/workflows/versions` | 200 | 200 | 200 | 200 | 200 |
| `/api/admin/health` | 200 | 200 | 200 | 200 | 200 |
| `/api/admin/exports` | 200 | 200 | 403 | 200 | 200 |

### 6.3 Quick role login payloads

`POST /api/auth/login`

Super Admin:
```json
{
  "email": "super.admin@demo.test",
  "password": "password",
  "device_name": "super-admin-ui"
}
```

Tenant Admin:
```json
{
  "email": "tenant.admin@demo.test",
  "password": "password",
  "device_name": "tenant-admin-ui"
}
```

Sales:
```json
{
  "email": "sales@demo.test",
  "password": "password",
  "device_name": "sales-ui"
}
```

Manager:
```json
{
  "email": "manager@demo.test",
  "password": "password",
  "device_name": "manager-ui"
}
```

Marketing:
```json
{
  "email": "marketing@demo.test",
  "password": "password",
  "device_name": "marketing-ui"
}
```

## 7. Real Frontend Test Example (Executed)

This was executed against:
- Backend: `http://127.0.0.1:8000`
- Admin: `http://127.0.0.1:5173`
- Date: `2026-02-12`

### Scenario
1. Login as `tenant.admin@demo.test`.
2. Create public lead.
3. Create segment.
4. Create email template.
5. Create campaign.
6. Run wizard steps.
7. Launch campaign.
8. Run queue worker.
9. Confirm logs + dashboard.

### Actual created records
- Lead: `id=55`, email `frontend.20260212101127@example.test`
- Segment: `id=6`, name `Frontend Segment 20260212101127`
- Template: `id=8`, name `Frontend Template 20260212101127`
- Campaign: `id=5`, name `Frontend Campaign 20260212101127`

### Actual result observed
- Campaign launch response: `Campaign launch queued successfully.`
- Campaign logs included:
  - `campaign.launch.requested`
  - `campaign.launch.dispatched`
  - `campaign.messages.generated`
  - `message.status.updated`
  - `campaign.message.sent`
- Dashboard metrics after queue processing:
  - `messages_sent: 1`
  - `messages_failed: 0`
  - `messages_queued: 0`

### Frontend validation points
1. Dashboard displays non-zero metrics.
2. Leads module shows lead `id=55`.
3. Segments module shows segment `id=6`.
4. Templates module shows template `id=8`.
5. Campaigns module shows campaign `id=5` with status `completed`.
6. Campaign logs panel shows send lifecycle events.
