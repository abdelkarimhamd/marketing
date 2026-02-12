# Marketion User Guide (Demo Credentials + Test Data)

## 1. Start the Project

### Backend (Laravel)
```powershell
cd c:\xampp\htdocs\marketion\backend
php artisan migrate
php artisan db:seed
php artisan serve --host=127.0.0.1 --port=8000
php artisan queue:work
```

### Admin (React)
```powershell
cd c:\xampp\htdocs\marketion\admin
cmd /c npm run dev -- --host 127.0.0.1 --port 5173
```

Admin UI URL:
- `http://127.0.0.1:5173`

API base URL:
- `http://127.0.0.1:8000`

## 2. Demo Credentials

All seeded users use this password:
- `password`

Users:
1. `super.admin@demo.test` (`super_admin`)
2. `tenant.admin@demo.test` (`tenant_admin`)
3. `sales@demo.test` (`sales`)

Seeded tenant:
- Name: `Demo Tenant`
- Slug: `demo-tenant`
- Domain: `demo.localhost`

Seeded public API key:
- `demo-public-key`

## 3. First Login Steps (Recommended Flow)

### Step 1: Login and get token
`POST /api/auth/login`

```json
{
  "email": "super.admin@demo.test",
  "password": "password",
  "device_name": "admin-ui"
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

1. `super_admin`:
   - Login, choose tenant, monitor all tenants.

2. `tenant_admin`:
   - Manage leads, segments, templates, campaigns, settings, API keys, webhook inbox.

3. `sales`:
   - Limited access (admin endpoints blocked by design).
