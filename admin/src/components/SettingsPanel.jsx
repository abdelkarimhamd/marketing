import { useCallback, useEffect, useState } from 'react'
import {
  Button,
  Card,
  CardContent,
  Divider,
  FormControl,
  Grid,
  InputLabel,
  MenuItem,
  Paper,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  TextField,
  Typography,
} from '@mui/material'
import { Save as SaveIcon, VpnKey as KeyIcon } from '@mui/icons-material'
import { apiRequest, formatDate } from '../lib/api'

function SettingsPanel({ token, tenantId, refreshKey, onNotify }) {
  const [settings, setSettings] = useState({
    providers: { email: 'mock', sms: 'mock', whatsapp: 'mock' },
    domains: [],
    slack: { enabled: false, webhook_url: '', channel: '' },
    rules: [],
  })
  const [tenant, setTenant] = useState(null)
  const [apiKeys, setApiKeys] = useState([])
  const [newApiKey, setNewApiKey] = useState({ name: '', abilities: 'public:leads:write', expires_at: '' })
  const [creatingKey, setCreatingKey] = useState(false)

  const loadSettings = useCallback(async () => {
    if (!tenantId) return
    try {
      const [settingsResponse, keyResponse] = await Promise.all([
        apiRequest(`/api/admin/settings?tenant_id=${tenantId}`, { token, tenantId }),
        apiRequest(`/api/admin/api-keys?tenant_id=${tenantId}&per_page=100`, { token, tenantId }),
      ])
      setTenant(settingsResponse.tenant ?? null)
      setSettings(settingsResponse.settings ?? settings)
      setApiKeys(keyResponse.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [onNotify, settings, tenantId, token])

  useEffect(() => {
    loadSettings()
  }, [loadSettings, refreshKey])

  const saveSettings = async () => {
    if (!tenantId) {
      onNotify('Select tenant to save settings.', 'warning')
      return
    }

    try {
      await apiRequest(`/api/admin/settings?tenant_id=${tenantId}`, {
        method: 'PUT',
        token,
        tenantId,
        body: {
          domain: tenant?.domain ?? '',
          providers: settings.providers,
          domains: settings.domains,
          slack: settings.slack,
        },
      })
      onNotify('Settings saved.', 'success')
      loadSettings()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const createApiKey = async () => {
    if (!tenantId) {
      onNotify('Select tenant first.', 'warning')
      return
    }
    setCreatingKey(true)
    try {
      const response = await apiRequest(`/api/admin/api-keys?tenant_id=${tenantId}`, {
        method: 'POST',
        token,
        tenantId,
        body: {
          name: newApiKey.name,
          abilities: newApiKey.abilities
            .split(',')
            .map((item) => item.trim())
            .filter(Boolean),
          expires_at: newApiKey.expires_at || null,
        },
      })
      onNotify(`API key created. Copy now: ${response.plain_text_key}`, 'success')
      setNewApiKey({ name: '', abilities: 'public:leads:write', expires_at: '' })
      loadSettings()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setCreatingKey(false)
    }
  }

  const revokeApiKey = async (apiKeyId) => {
    try {
      await apiRequest(`/api/admin/api-keys/${apiKeyId}/revoke?tenant_id=${tenantId}`, {
        method: 'POST',
        token,
        tenantId,
      })
      onNotify('API key revoked.', 'success')
      loadSettings()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const deleteApiKey = async (apiKeyId) => {
    try {
      await apiRequest(`/api/admin/api-keys/${apiKeyId}?tenant_id=${tenantId}`, {
        method: 'DELETE',
        token,
        tenantId,
      })
      onNotify('API key deleted.', 'success')
      loadSettings()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  return (
    <Stack spacing={2}>
      <Typography variant="h5">Settings</Typography>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, lg: 7 }}>
          <Card>
            <CardContent>
              <Typography variant="h6">Providers + Domains + Slack</Typography>
              <Divider sx={{ my: 1.2 }} />
              <Stack spacing={1.6}>
                <TextField
                  label="Primary Domain"
                  value={tenant?.domain ?? ''}
                  onChange={(event) => setTenant((prev) => ({ ...(prev ?? {}), domain: event.target.value }))}
                />
                <TextField
                  label="Extra Domains (comma separated)"
                  value={(settings.domains ?? []).join(', ')}
                  onChange={(event) =>
                    setSettings((prev) => ({
                      ...prev,
                      domains: event.target.value
                        .split(',')
                        .map((domain) => domain.trim())
                        .filter(Boolean),
                    }))
                  }
                />
                <Grid container spacing={1.2}>
                  <Grid size={{ xs: 12, sm: 4 }}>
                    <FormControl size="small" fullWidth>
                      <InputLabel>Email Provider</InputLabel>
                      <Select
                        label="Email Provider"
                        value={settings.providers?.email ?? 'mock'}
                        onChange={(event) =>
                          setSettings((prev) => ({
                            ...prev,
                            providers: {
                              ...prev.providers,
                              email: event.target.value,
                            },
                          }))
                        }
                      >
                        <MenuItem value="mock">mock</MenuItem>
                        <MenuItem value="smtp">smtp</MenuItem>
                      </Select>
                    </FormControl>
                  </Grid>
                  <Grid size={{ xs: 12, sm: 4 }}>
                    <FormControl size="small" fullWidth>
                      <InputLabel>SMS Provider</InputLabel>
                      <Select
                        label="SMS Provider"
                        value={settings.providers?.sms ?? 'mock'}
                        onChange={(event) =>
                          setSettings((prev) => ({
                            ...prev,
                            providers: {
                              ...prev.providers,
                              sms: event.target.value,
                            },
                          }))
                        }
                      >
                        <MenuItem value="mock">mock</MenuItem>
                        <MenuItem value="twilio">twilio</MenuItem>
                      </Select>
                    </FormControl>
                  </Grid>
                  <Grid size={{ xs: 12, sm: 4 }}>
                    <FormControl size="small" fullWidth>
                      <InputLabel>WA Provider</InputLabel>
                      <Select
                        label="WA Provider"
                        value={settings.providers?.whatsapp ?? 'mock'}
                        onChange={(event) =>
                          setSettings((prev) => ({
                            ...prev,
                            providers: {
                              ...prev.providers,
                              whatsapp: event.target.value,
                            },
                          }))
                        }
                      >
                        <MenuItem value="mock">mock</MenuItem>
                        <MenuItem value="meta">meta</MenuItem>
                      </Select>
                    </FormControl>
                  </Grid>
                </Grid>
                <TextField
                  label="Slack Webhook URL"
                  value={settings.slack?.webhook_url ?? ''}
                  onChange={(event) =>
                    setSettings((prev) => ({
                      ...prev,
                      slack: {
                        ...(prev.slack ?? {}),
                        webhook_url: event.target.value,
                      },
                    }))
                  }
                />
                <TextField
                  label="Slack Channel"
                  value={settings.slack?.channel ?? ''}
                  onChange={(event) =>
                    setSettings((prev) => ({
                      ...prev,
                      slack: {
                        ...(prev.slack ?? {}),
                        channel: event.target.value,
                      },
                    }))
                  }
                />
                <Button variant="contained" startIcon={<SaveIcon />} onClick={saveSettings}>
                  Save Settings
                </Button>
              </Stack>
            </CardContent>
          </Card>
        </Grid>

        <Grid size={{ xs: 12, lg: 5 }}>
          <Card>
            <CardContent>
              <Typography variant="h6">Assignment Rules Snapshot</Typography>
              <Divider sx={{ my: 1.2 }} />
              <Stack spacing={1}>
                {(settings.rules ?? []).length === 0 && <Typography color="text.secondary">No assignment rules found.</Typography>}
                {(settings.rules ?? []).map((rule) => (
                  <Paper key={rule.id} variant="outlined" sx={{ p: 1 }}>
                    <Typography variant="body2">
                      <strong>{rule.name}</strong> · {rule.strategy}
                    </Typography>
                    <Typography variant="caption" color="text.secondary">
                      Priority {rule.priority} · intake {rule.auto_assign_on_intake ? 'on' : 'off'} · import {rule.auto_assign_on_import ? 'on' : 'off'}
                    </Typography>
                  </Paper>
                ))}
              </Stack>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Card>
        <CardContent>
          <Typography variant="h6">API Keys</Typography>
          <Divider sx={{ my: 1.2 }} />
          <Grid container spacing={1.2} sx={{ mb: 1.5 }}>
            <Grid size={{ xs: 12, md: 4 }}>
              <TextField
                fullWidth
                size="small"
                label="Name"
                value={newApiKey.name}
                onChange={(event) => setNewApiKey((prev) => ({ ...prev, name: event.target.value }))}
              />
            </Grid>
            <Grid size={{ xs: 12, md: 5 }}>
              <TextField
                fullWidth
                size="small"
                label="Abilities (comma separated)"
                value={newApiKey.abilities}
                onChange={(event) => setNewApiKey((prev) => ({ ...prev, abilities: event.target.value }))}
              />
            </Grid>
            <Grid size={{ xs: 12, md: 3 }}>
              <TextField
                fullWidth
                size="small"
                type="datetime-local"
                label="Expires At"
                InputLabelProps={{ shrink: true }}
                value={newApiKey.expires_at}
                onChange={(event) => setNewApiKey((prev) => ({ ...prev, expires_at: event.target.value }))}
              />
            </Grid>
            <Grid size={12}>
              <Button variant="contained" startIcon={<KeyIcon />} onClick={createApiKey} disabled={creatingKey || !newApiKey.name.trim()}>
                {creatingKey ? 'Creating...' : 'Create API Key'}
              </Button>
            </Grid>
          </Grid>
          <Table size="small">
            <TableHead>
              <TableRow>
                <TableCell>Name</TableCell>
                <TableCell>Prefix</TableCell>
                <TableCell>Status</TableCell>
                <TableCell>Expires</TableCell>
                <TableCell align="right">Actions</TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {apiKeys.map((apiKey) => (
                <TableRow key={apiKey.id}>
                  <TableCell>{apiKey.name}</TableCell>
                  <TableCell>{apiKey.prefix}</TableCell>
                  <TableCell>{apiKey.revoked_at ? 'revoked' : 'active'}</TableCell>
                  <TableCell>{formatDate(apiKey.expires_at)}</TableCell>
                  <TableCell align="right">
                    <Stack direction="row" spacing={1} justifyContent="flex-end">
                      {!apiKey.revoked_at && (
                        <Button size="small" color="warning" onClick={() => revokeApiKey(apiKey.id)}>
                          Revoke
                        </Button>
                      )}
                      <Button size="small" color="error" onClick={() => deleteApiKey(apiKey.id)}>
                        Delete
                      </Button>
                    </Stack>
                  </TableCell>
                </TableRow>
              ))}
              {apiKeys.length === 0 && (
                <TableRow>
                  <TableCell colSpan={5}>
                    <Typography align="center" color="text.secondary" sx={{ py: 1.5 }}>
                      No API keys.
                    </Typography>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </Stack>
  )
}

export default SettingsPanel
