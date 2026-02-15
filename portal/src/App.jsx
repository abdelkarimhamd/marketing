import { useEffect, useMemo, useState } from 'react'

const API_BASE = import.meta.env.VITE_API_BASE_URL ?? 'http://127.0.0.1:8000'
const TENANT_SLUG = import.meta.env.VITE_TENANT_SLUG ?? ''

async function api(path, { method = 'GET', body } = {}) {
  const headers = { Accept: 'application/json' }
  if (TENANT_SLUG) headers['X-Tenant-Slug'] = TENANT_SLUG

  const options = { method, headers }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    options.body = JSON.stringify(body)
  }

  const response = await fetch(`${API_BASE}${path}`, options)
  const payload = await response.json()

  if (!response.ok) {
    throw new Error(payload?.message || `${response.status} ${response.statusText}`)
  }

  return payload
}

function App() {
  const [config, setConfig] = useState(null)
  const [mode, setMode] = useState('quote')
  const [statusToken, setStatusToken] = useState('')
  const [trackingToken, setTrackingToken] = useState('')
  const [statusData, setStatusData] = useState(null)
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(false)
  const [quoteForm, setQuoteForm] = useState({ first_name: '', last_name: '', email: '', phone: '', company: '', quote_budget: '', quote_timeline: '', message: '' })
  const [demoForm, setDemoForm] = useState({ first_name: '', last_name: '', email: '', phone: '', company: '', preferred_at: '', booking_channel: 'online', message: '' })

  useEffect(() => {
    const load = async () => {
      try {
        const response = await api('/api/public/portal')
        setConfig(response)
      } catch (error) {
        setMessage(error.message)
      }
    }

    load()
  }, [])

  const branding = useMemo(() => ({
    name: config?.tenant?.name || 'Portal',
    headline: config?.portal?.headline || 'Customer Portal',
    subtitle: config?.portal?.subtitle || 'Request quote, upload docs, book meeting, and track status.',
    primary: config?.branding?.primary_color || '#146c94',
    accent: config?.branding?.accent_color || '#f59e0b',
  }), [config])

  const submitQuote = async () => {
    setLoading(true)
    setMessage('')
    try {
      const response = await api('/api/public/portal/request-quote', {
        method: 'POST',
        body: quoteForm,
      })
      setTrackingToken(response.tracking_token || '')
      setStatusToken((response.status_url || '').split('/').pop() || '')
      setMessage('Quote request submitted successfully.')
    } catch (error) {
      setMessage(error.message)
    } finally {
      setLoading(false)
    }
  }

  const submitDemo = async () => {
    setLoading(true)
    setMessage('')
    try {
      const response = await api('/api/public/portal/book-demo', {
        method: 'POST',
        body: demoForm,
      })
      setTrackingToken(response.tracking_token || '')
      setStatusToken((response.status_url || '').split('/').pop() || '')
      setMessage('Demo booking submitted successfully.')
    } catch (error) {
      setMessage(error.message)
    } finally {
      setLoading(false)
    }
  }

  const loadStatus = async () => {
    if (!statusToken.trim()) {
      setMessage('Tracking token is required.')
      return
    }

    setLoading(true)
    setMessage('')
    try {
      const response = await api(`/api/public/portal/status/${statusToken.trim()}`)
      setStatusData(response)
    } catch (error) {
      setMessage(error.message)
      setStatusData(null)
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="app" style={{ '--brand': branding.primary, '--accent': branding.accent }}>
      <main className="shell">
        <header className="hero">
          <h1>{branding.headline}</h1>
          <p>{branding.subtitle}</p>
          <small>{branding.name}</small>
        </header>

        <nav className="tabs">
          <button className={mode === 'quote' ? 'active' : ''} onClick={() => setMode('quote')}>Request Quote</button>
          <button className={mode === 'demo' ? 'active' : ''} onClick={() => setMode('demo')}>Book Demo</button>
          <button className={mode === 'status' ? 'active' : ''} onClick={() => setMode('status')}>Track Status</button>
        </nav>

        {message && <div className="notice">{message}</div>}

        {mode === 'quote' && (
          <section className="panel">
            <div className="grid">
              <input placeholder="First Name" value={quoteForm.first_name} onChange={(e) => setQuoteForm((p) => ({ ...p, first_name: e.target.value }))} />
              <input placeholder="Last Name" value={quoteForm.last_name} onChange={(e) => setQuoteForm((p) => ({ ...p, last_name: e.target.value }))} />
              <input placeholder="Email" value={quoteForm.email} onChange={(e) => setQuoteForm((p) => ({ ...p, email: e.target.value }))} />
              <input placeholder="Phone" value={quoteForm.phone} onChange={(e) => setQuoteForm((p) => ({ ...p, phone: e.target.value }))} />
              <input placeholder="Company" value={quoteForm.company} onChange={(e) => setQuoteForm((p) => ({ ...p, company: e.target.value }))} />
              <input placeholder="Budget" value={quoteForm.quote_budget} onChange={(e) => setQuoteForm((p) => ({ ...p, quote_budget: e.target.value }))} />
              <input placeholder="Timeline" value={quoteForm.quote_timeline} onChange={(e) => setQuoteForm((p) => ({ ...p, quote_timeline: e.target.value }))} />
              <textarea placeholder="Message" value={quoteForm.message} onChange={(e) => setQuoteForm((p) => ({ ...p, message: e.target.value }))} />
            </div>
            <button onClick={submitQuote} disabled={loading}>{loading ? 'Submitting...' : 'Submit Quote'}</button>
          </section>
        )}

        {mode === 'demo' && (
          <section className="panel">
            <div className="grid">
              <input placeholder="First Name" value={demoForm.first_name} onChange={(e) => setDemoForm((p) => ({ ...p, first_name: e.target.value }))} />
              <input placeholder="Last Name" value={demoForm.last_name} onChange={(e) => setDemoForm((p) => ({ ...p, last_name: e.target.value }))} />
              <input placeholder="Email" value={demoForm.email} onChange={(e) => setDemoForm((p) => ({ ...p, email: e.target.value }))} />
              <input placeholder="Phone" value={demoForm.phone} onChange={(e) => setDemoForm((p) => ({ ...p, phone: e.target.value }))} />
              <input placeholder="Company" value={demoForm.company} onChange={(e) => setDemoForm((p) => ({ ...p, company: e.target.value }))} />
              <input type="datetime-local" value={demoForm.preferred_at} onChange={(e) => setDemoForm((p) => ({ ...p, preferred_at: e.target.value }))} />
              <input placeholder="Booking channel" value={demoForm.booking_channel} onChange={(e) => setDemoForm((p) => ({ ...p, booking_channel: e.target.value }))} />
              <textarea placeholder="Message" value={demoForm.message} onChange={(e) => setDemoForm((p) => ({ ...p, message: e.target.value }))} />
            </div>
            <button onClick={submitDemo} disabled={loading}>{loading ? 'Submitting...' : 'Book Demo'}</button>
          </section>
        )}

        {mode === 'status' && (
          <section className="panel">
            <div className="grid">
              <input placeholder="Status token" value={statusToken} onChange={(e) => setStatusToken(e.target.value)} />
              <button onClick={loadStatus} disabled={loading}>{loading ? 'Loading...' : 'Load Status'}</button>
              {trackingToken && <small>Latest tracking token: {trackingToken}</small>}
            </div>

            {statusData && (
              <article className="status">
                <h3>Lead #{statusData.lead?.id}</h3>
                <p>Status: {statusData.lead?.status}</p>
                <p>Owner: {statusData.lead?.owner?.name || '-'}</p>
                <div className="timeline">
                  {(statusData.timeline || []).slice(0, 12).map((item) => (
                    <div key={item.id} className="timeline-row">
                      <strong>{item.type}</strong>
                      <span>{item.description || '-'}</span>
                      <small>{item.created_at || '-'}</small>
                    </div>
                  ))}
                </div>
              </article>
            )}
          </section>
        )}
      </main>
    </div>
  )
}

export default App
