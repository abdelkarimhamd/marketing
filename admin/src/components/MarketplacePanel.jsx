import { useCallback, useEffect, useState } from 'react'
import {
  Alert,
  Button,
  Card,
  CardContent,
  Divider,
  Grid,
  Paper,
  Stack,
  TextField,
  Typography,
} from '@mui/material'
import { apiRequest, formatDate } from '../lib/api'

function MarketplacePanel({ token, tenantId, refreshKey, onNotify, can = () => true }) {
  const [apps, setApps] = useState([])
  const [installs, setInstalls] = useState([])
  const [deliveries, setDeliveries] = useState([])
  const [webhookUrlByInstall, setWebhookUrlByInstall] = useState({})
  const canInstall = can('marketplace.install')
  const canUpdate = can('marketplace.update')

  const load = useCallback(async () => {
    if (!tenantId) return

    try {
      const [appsResponse, installsResponse, deliveriesResponse] = await Promise.all([
        apiRequest('/api/admin/marketplace/apps', { token, tenantId }),
        apiRequest('/api/admin/marketplace/installs', { token, tenantId }),
        apiRequest('/api/admin/marketplace/deliveries?per_page=20', { token, tenantId }),
      ])
      setApps(appsResponse.data ?? [])
      setInstalls(installsResponse.data ?? [])
      setDeliveries(deliveriesResponse.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    load()
  }, [load, refreshKey])

  const install = async (app) => {
    if (!canInstall) {
      onNotify('You do not have permission to install marketplace apps.', 'warning')
      return
    }

    try {
      const response = await apiRequest(`/api/admin/marketplace/apps/${app.id}/install`, {
        method: 'POST',
        token,
        tenantId,
      })
      onNotify(`Installed ${app.name}. Secret: ${response.new_secret}`, 'success')
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const uninstall = async (installRow) => {
    if (!canInstall) {
      onNotify('You do not have permission to uninstall apps.', 'warning')
      return
    }

    try {
      await apiRequest(`/api/admin/marketplace/installs/${installRow.id}/uninstall`, {
        method: 'POST',
        token,
        tenantId,
      })
      onNotify('App uninstalled.', 'success')
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const rotateSecret = async (installRow) => {
    if (!canUpdate) {
      onNotify('You do not have permission to rotate secrets.', 'warning')
      return
    }

    try {
      const response = await apiRequest(`/api/admin/marketplace/installs/${installRow.id}/rotate-secret`, {
        method: 'POST',
        token,
        tenantId,
      })
      onNotify(`Secret rotated. New secret: ${response.secret}`, 'success')
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const addWebhook = async (installRow) => {
    const endpoint = (webhookUrlByInstall[installRow.id] || '').trim()

    if (!endpoint) {
      onNotify('Webhook URL is required.', 'warning')
      return
    }

    try {
      await apiRequest(`/api/admin/marketplace/installs/${installRow.id}/webhooks`, {
        method: 'POST',
        token,
        tenantId,
        body: {
          endpoint_url: endpoint,
          events_json: ['*'],
          is_active: true,
        },
      })
      onNotify('Webhook added.', 'success')
      setWebhookUrlByInstall((prev) => ({ ...prev, [installRow.id]: '' }))
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const retry = async (delivery) => {
    try {
      await apiRequest(`/api/admin/marketplace/deliveries/${delivery.id}/retry`, {
        method: 'POST',
        token,
        tenantId,
      })
      onNotify('Delivery retry queued.', 'success')
      load()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  if (!tenantId) {
    return <Alert severity="info">Select a tenant to manage marketplace apps.</Alert>
  }

  return (
    <Grid container spacing={2}>
      <Grid size={{ xs: 12, lg: 4 }}>
        <Card>
          <CardContent>
            <Typography variant="h6">Apps Catalog</Typography>
            <Divider sx={{ my: 1 }} />
            <Stack spacing={1}>
              {apps.map((app) => (
                <Paper key={app.id} variant="outlined" sx={{ p: 1 }}>
                  <Typography variant="body2">{app.name}</Typography>
                  <Typography variant="caption" color="text.secondary">{app.slug}</Typography>
                  <Stack direction="row" spacing={1} sx={{ mt: 1 }}>
                    <Button size="small" variant="outlined" onClick={() => install(app)} disabled={!canInstall || app.is_installed}>
                      {app.is_installed ? 'Installed' : 'Install'}
                    </Button>
                  </Stack>
                </Paper>
              ))}
            </Stack>
          </CardContent>
        </Card>
      </Grid>

      <Grid size={{ xs: 12, lg: 4 }}>
        <Card>
          <CardContent>
            <Typography variant="h6">Installed Apps</Typography>
            <Divider sx={{ my: 1 }} />
            <Stack spacing={1}>
              {installs.map((installRow) => (
                <Paper key={installRow.id} variant="outlined" sx={{ p: 1 }}>
                  <Typography variant="body2">{installRow.app?.name || `App #${installRow.marketplace_app_id}`}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    Status: {installRow.status} • Installed {formatDate(installRow.installed_at)}
                  </Typography>
                  <Stack direction="row" spacing={0.6} sx={{ mt: 1 }}>
                    <Button size="small" onClick={() => rotateSecret(installRow)} disabled={!canUpdate}>Rotate Secret</Button>
                    <Button size="small" color="error" onClick={() => uninstall(installRow)} disabled={!canInstall || installRow.status === 'uninstalled'}>
                      Uninstall
                    </Button>
                  </Stack>
                  <Stack direction="row" spacing={0.6} sx={{ mt: 1 }}>
                    <TextField
                      size="small"
                      placeholder="Webhook URL"
                      value={webhookUrlByInstall[installRow.id] || ''}
                      onChange={(event) => setWebhookUrlByInstall((prev) => ({ ...prev, [installRow.id]: event.target.value }))}
                      fullWidth
                    />
                    <Button size="small" variant="outlined" onClick={() => addWebhook(installRow)} disabled={!canUpdate}>Add</Button>
                  </Stack>
                </Paper>
              ))}
              {installs.length === 0 && <Typography color="text.secondary">No installs yet.</Typography>}
            </Stack>
          </CardContent>
        </Card>
      </Grid>

      <Grid size={{ xs: 12, lg: 4 }}>
        <Card>
          <CardContent>
            <Typography variant="h6">Delivery Log</Typography>
            <Divider sx={{ my: 1 }} />
            <Stack spacing={1}>
              {deliveries.map((delivery) => (
                <Paper key={delivery.id} variant="outlined" sx={{ p: 1 }}>
                  <Typography variant="body2">{delivery.domain_event?.event_name || 'event'} • {delivery.status}</Typography>
                  <Typography variant="caption" color="text.secondary">HTTP {delivery.response_code || '-'} • {formatDate(delivery.created_at)}</Typography>
                  <Stack direction="row" spacing={0.6} sx={{ mt: 1 }}>
                    <Button size="small" onClick={() => retry(delivery)}>Retry</Button>
                  </Stack>
                </Paper>
              ))}
              {deliveries.length === 0 && <Typography color="text.secondary">No deliveries yet.</Typography>}
            </Stack>
          </CardContent>
        </Card>
      </Grid>
    </Grid>
  )
}

export default MarketplacePanel
