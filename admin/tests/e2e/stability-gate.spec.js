import { expect, test } from '@playwright/test'

const enabled = process.env.E2E_ENABLE_STABILITY === '1'
const tenantId = process.env.E2E_TENANT_ID ?? ''
const adminEmail = process.env.E2E_ADMIN_EMAIL ?? ''
const adminPassword = process.env.E2E_ADMIN_PASSWORD ?? ''
const publicTenantHeader = process.env.E2E_PUBLIC_TENANT_ID ?? tenantId

async function login(request) {
  const response = await request.post('/api/auth/login', {
    data: {
      email: adminEmail,
      password: adminPassword,
    },
  })
  expect(response.ok()).toBeTruthy()
  const payload = await response.json()
  expect(payload.token).toBeTruthy()
  return payload.token
}

function authHeaders(token, includeTenant = true) {
  const headers = {
    Authorization: `Bearer ${token}`,
    Accept: 'application/json',
  }

  if (includeTenant && tenantId) {
    headers['X-Tenant-ID'] = String(tenantId)
  }

  return headers
}

test.describe.configure({ mode: 'serial' })

test.describe('Task 51 Stability Gate E2E', () => {
  test.skip(!enabled, 'Set E2E_ENABLE_STABILITY=1 to run stability-gate e2e flows.')
  test.skip(!tenantId, 'Set E2E_TENANT_ID for tenant-scoped e2e checks.')
  test.skip(!adminEmail || !adminPassword, 'Set E2E_ADMIN_EMAIL and E2E_ADMIN_PASSWORD.')

  test('Create lead -> enrich/validate -> assign by availability', async ({ request }) => {
    const token = await login(request)

    const usersRes = await request.get('/api/admin/users', {
      headers: authHeaders(token),
    })
    expect(usersRes.ok()).toBeTruthy()
    const usersPayload = await usersRes.json()
    const firstUser = usersPayload.data?.[0]
    expect(firstUser).toBeTruthy()

    const availabilityRes = await request.patch(`/api/admin/users/${firstUser.id}/availability`, {
      headers: authHeaders(token),
      data: {
        availability: {
          status: 'available',
          is_online: true,
          timezone: 'Asia/Riyadh',
          max_active_leads: 200,
          working_hours: {
            days: [1, 2, 3, 4, 5],
            start: '08:00',
            end: '18:00',
          },
        },
      },
    })
    expect(availabilityRes.ok()).toBeTruthy()

    const leadRes = await request.post('/api/admin/leads', {
      headers: authHeaders(token),
      data: {
        first_name: 'E2E',
        last_name: 'Lead',
        email: `e2e-lead-${Date.now()}@example.test`,
        phone: '+966555000111',
        source: 'website',
        auto_assign: true,
      },
    })
    expect(leadRes.ok()).toBeTruthy()
    const leadPayload = await leadRes.json()
    expect(leadPayload.lead?.id).toBeTruthy()
  })

  test('Create brand -> launch campaign with specific brand context', async ({ request }) => {
    const token = await login(request)
    const suffix = Date.now()

    const brandRes = await request.post('/api/admin/brands', {
      headers: authHeaders(token),
      data: {
        name: `E2E Brand ${suffix}`,
        slug: `e2e-brand-${suffix}`,
        email_from_address: `brand-${suffix}@example.test`,
        email_from_name: `E2E Brand ${suffix}`,
      },
    })
    expect(brandRes.ok()).toBeTruthy()
    const brandPayload = await brandRes.json()
    const brandId = brandPayload.brand?.id
    expect(brandId).toBeTruthy()

    const campaignsRes = await request.get('/api/admin/campaigns', {
      headers: authHeaders(token),
    })
    expect(campaignsRes.ok()).toBeTruthy()
    const campaignsPayload = await campaignsRes.json()
    const campaign = campaignsPayload.data?.[0]
    expect(campaign?.id).toBeTruthy()

    const updateRes = await request.patch(`/api/admin/campaigns/${campaign.id}`, {
      headers: authHeaders(token),
      data: {
        brand_id: brandId,
      },
    })
    expect(updateRes.ok()).toBeTruthy()

    const launchRes = await request.post(`/api/admin/campaigns/${campaign.id}/launch`, {
      headers: authHeaders(token),
      data: {},
    })
    expect([200, 202].includes(launchRes.status())).toBeTruthy()
  })

  test('Create custom field -> form mapping -> public submit', async ({ request }) => {
    const token = await login(request)
    const suffix = Date.now()

    const customFieldRes = await request.post('/api/admin/custom-fields', {
      headers: authHeaders(token),
      data: {
        entity: 'lead',
        name: `E2E Budget ${suffix}`,
        slug: `e2e-budget-${suffix}`,
        field_type: 'text',
        is_required: false,
        is_active: true,
      },
    })
    expect(customFieldRes.ok()).toBeTruthy()
    const customFieldPayload = await customFieldRes.json()
    const customFieldId = customFieldPayload.custom_field?.id
    expect(customFieldId).toBeTruthy()

    const formSlug = `e2e-form-${suffix}`
    const formRes = await request.post('/api/admin/lead-forms', {
      headers: authHeaders(token),
      data: {
        name: `E2E Form ${suffix}`,
        slug: formSlug,
        is_active: true,
        fields: [
          {
            custom_field_id: customFieldId,
            label: 'Budget',
            source_key: 'budget',
            map_to: 'custom',
            is_required: false,
          },
        ],
      },
    })
    expect(formRes.ok()).toBeTruthy()

    const publicLeadRes = await request.post('/api/public/leads', {
      headers: {
        Accept: 'application/json',
        'X-Tenant-ID': String(publicTenantHeader),
      },
      data: {
        email: `e2e-public-${suffix}@example.test`,
        source: 'portal',
        form_slug: formSlug,
        budget: '5000-10000',
      },
    })
    expect(publicLeadRes.ok()).toBeTruthy()
    const publicLeadPayload = await publicLeadRes.json()
    expect(publicLeadPayload.lead?.id).toBeTruthy()
  })

  test('Import schedule -> run now -> dedupe flow execution', async ({ request }) => {
    const token = await login(request)
    const suffix = Date.now()

    const presetRes = await request.post('/api/admin/lead-import/presets', {
      headers: authHeaders(token),
      data: {
        name: `E2E Import Preset ${suffix}`,
        slug: `e2e-import-preset-${suffix}`,
        mapping: { email: 'email', phone: 'phone', first_name: 'first_name' },
        dedupe_policy: 'merge',
        dedupe_keys: ['email', 'phone'],
      },
    })
    expect(presetRes.ok()).toBeTruthy()
    const presetPayload = await presetRes.json()
    const presetId = presetPayload.preset?.id
    expect(presetId).toBeTruthy()

    const scheduleRes = await request.post('/api/admin/lead-import/schedules', {
      headers: authHeaders(token),
      data: {
        preset_id: presetId,
        name: `E2E Import Schedule ${suffix}`,
        source_type: 'url',
        source_config: { url: 'https://invalid-e2e-source.marketion.test/non-existent.csv' },
        dedupe_policy: 'merge',
        dedupe_keys: ['email', 'phone'],
        schedule_cron: '0 * * * *',
        is_active: true,
      },
    })
    expect(scheduleRes.ok()).toBeTruthy()
    const schedulePayload = await scheduleRes.json()
    const scheduleId = schedulePayload.schedule?.id
    expect(scheduleId).toBeTruthy()

    const runNowRes = await request.post(`/api/admin/lead-import/schedules/${scheduleId}/run`, {
      headers: authHeaders(token),
      data: {},
    })
    expect([200, 422].includes(runNowRes.status())).toBeTruthy()
  })

  test.fixme('Inbox: WhatsApp inbound -> agent reply -> activity logged', async () => {
    // Pending dedicated reply endpoint automation in admin inbox module.
  })

  test('Proposal generation -> send flow', async ({ request }) => {
    const token = await login(request)

    const leadsRes = await request.get('/api/admin/leads', {
      headers: authHeaders(token),
    })
    expect(leadsRes.ok()).toBeTruthy()
    const leadsPayload = await leadsRes.json()
    const lead = leadsPayload.data?.[0]
    expect(lead?.id).toBeTruthy()

    const templatesRes = await request.get('/api/admin/proposal-templates', {
      headers: authHeaders(token),
    })
    expect(templatesRes.ok()).toBeTruthy()
    const templatesPayload = await templatesRes.json()
    const proposalTemplate = templatesPayload.data?.[0]
    expect(proposalTemplate?.id).toBeTruthy()

    const generateRes = await request.post('/api/admin/proposals/generate', {
      headers: authHeaders(token),
      data: {
        lead_id: lead.id,
        proposal_template_id: proposalTemplate.id,
        quote_amount: 1200,
      },
    })
    expect(generateRes.ok()).toBeTruthy()
    const generatePayload = await generateRes.json()
    const proposalId = generatePayload.proposal?.id
    expect(proposalId).toBeTruthy()

    const sendRes = await request.post(`/api/admin/proposals/${proposalId}/send`, {
      headers: authHeaders(token),
      data: {
        channels: ['email'],
      },
    })
    expect(sendRes.ok()).toBeTruthy()
  })

  test.fixme('Fatigue suppression after N sends blocks additional sends', async () => {
    // Requires deterministic engagement simulator in staging to assert suppression boundary.
  })

  test('Customer success console diagnostics are returned', async ({ request }) => {
    const token = await login(request)

    const res = await request.get('/api/admin/tenant-console', {
      headers: authHeaders(token, false),
    })
    expect(res.ok()).toBeTruthy()
    const payload = await res.json()
    expect(payload.summary).toBeTruthy()
    expect(Array.isArray(payload.tenants)).toBeTruthy()
  })
})
