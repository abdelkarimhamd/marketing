const API_BASE = process.env.EXPO_PUBLIC_API_BASE_URL || 'http://127.0.0.1:8000'

async function request(path, { method = 'GET', token, tenantId, body } = {}) {
  const headers = {
    Accept: 'application/json',
  }

  if (token) headers.Authorization = `Bearer ${token}`
  if (tenantId) headers['X-Tenant-ID'] = String(tenantId)

  const options = { method, headers }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    options.body = JSON.stringify(body)
  }

  const response = await fetch(`${API_BASE}${path}`, options)
  const text = await response.text()
  const data = text ? JSON.parse(text) : {}

  if (!response.ok) {
    throw new Error(data?.message || `${response.status} ${response.statusText}`)
  }

  return data
}

export { API_BASE, request }
