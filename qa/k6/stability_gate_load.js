import http from 'k6/http'
import { check, sleep } from 'k6'
import { SharedArray } from 'k6/data'

const baseUrl = __ENV.BASE_URL || 'http://127.0.0.1:8000'
const bearerToken = __ENV.BEARER_TOKEN || ''
const tenantId = __ENV.TENANT_ID || ''
const publicTenantId = __ENV.PUBLIC_TENANT_ID || tenantId
const importScheduleId = __ENV.IMPORT_SCHEDULE_ID || ''
const campaignId = __ENV.CAMPAIGN_ID || ''

const headers = {
  Authorization: `Bearer ${bearerToken}`,
  Accept: 'application/json',
  'X-Tenant-ID': String(tenantId),
}

const publicHeaders = {
  Accept: 'application/json',
  'X-Tenant-ID': String(publicTenantId),
}

const intakePayloads = new SharedArray('lead-intake-payloads', function () {
  const rows = []

  for (let i = 0; i < 1000; i += 1) {
    rows.push(
      JSON.stringify({
        first_name: `Load${i}`,
        last_name: 'Test',
        email: `load-${i}-${Date.now()}@example.test`,
        source: 'load_test',
      }),
    )
  }

  return rows
})

export const options = {
  scenarios: {
    lead_intake_burst: {
      executor: 'constant-arrival-rate',
      rate: 100,
      timeUnit: '1s',
      duration: '2m',
      preAllocatedVUs: 50,
      maxVUs: 200,
      exec: 'leadIntakeBurst',
    },
    campaign_launch_pressure: {
      executor: 'ramping-vus',
      startVUs: 1,
      stages: [
        { duration: '30s', target: 5 },
        { duration: '1m', target: 20 },
        { duration: '30s', target: 0 },
      ],
      gracefulRampDown: '15s',
      exec: 'campaignLaunchPressure',
    },
    inbox_reads: {
      executor: 'constant-vus',
      vus: 15,
      duration: '2m',
      exec: 'inboxReads',
    },
    import_run_now_pressure: {
      executor: 'shared-iterations',
      vus: 10,
      iterations: 40,
      maxDuration: '2m',
      exec: 'importRunNowPressure',
    },
  },
  thresholds: {
    checks: ['rate>0.95'],
    http_req_failed: ['rate<0.02'],
    http_req_duration: ['p(95)<1200', 'p(99)<2500'],
  },
}

export function leadIntakeBurst() {
  const payload = intakePayloads[Math.floor(Math.random() * intakePayloads.length)]
  const res = http.post(`${baseUrl}/api/public/leads`, payload, {
    headers: { ...publicHeaders, 'Content-Type': 'application/json' },
  })

  check(res, {
    'lead intake accepted': (r) => [201, 422, 429].includes(r.status),
  })
  sleep(0.1)
}

export function campaignLaunchPressure() {
  if (!campaignId || !bearerToken || !tenantId) {
    sleep(1)
    return
  }

  const res = http.post(
    `${baseUrl}/api/admin/campaigns/${campaignId}/launch`,
    JSON.stringify({}),
    {
      headers: { ...headers, 'Content-Type': 'application/json' },
    },
  )

  check(res, {
    'campaign launch endpoint responsive': (r) => [200, 202, 422].includes(r.status),
  })
  sleep(0.2)
}

export function inboxReads() {
  if (!bearerToken || !tenantId) {
    sleep(1)
    return
  }

  const res = http.get(`${baseUrl}/api/admin/inbox`, { headers })

  check(res, {
    'inbox endpoint responsive': (r) => [200, 204].includes(r.status),
  })
  sleep(0.25)
}

export function importRunNowPressure() {
  if (!importScheduleId || !bearerToken || !tenantId) {
    sleep(1)
    return
  }

  const res = http.post(
    `${baseUrl}/api/admin/lead-import/schedules/${importScheduleId}/run`,
    JSON.stringify({}),
    {
      headers: { ...headers, 'Content-Type': 'application/json' },
    },
  )

  check(res, {
    'import run-now endpoint responsive': (r) => [200, 202, 422].includes(r.status),
  })
  sleep(0.2)
}
