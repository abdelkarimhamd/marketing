import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Button,
  Card,
  CardContent,
  Checkbox,
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
  Tab,
  Tabs,
  TextField,
  Typography,
} from '@mui/material'
import { Save as SaveIcon, VpnKey as KeyIcon } from '@mui/icons-material'
import { apiRequest, formatDate } from '../lib/api'

const DEFAULT_SETTINGS = {
  providers: { email: 'mock', sms: 'mock', whatsapp: 'mock' },
  email_delivery: {
    mode: 'platform',
    from_address: '',
    from_name: '',
    use_custom_smtp: false,
    smtp_host: '',
    smtp_port: 587,
    smtp_username: '',
    smtp_password: '',
    smtp_encryption: 'tls',
    has_smtp_password: false,
  },
  domains: [],
  custom_domains: [],
  slack: { enabled: false, webhook_url: '', channel: '' },
  branding: {
    logo_url: '',
    primary_color: '',
    secondary_color: '',
    accent_color: '',
    email_footer: '',
    landing_theme: 'default',
  },
  rules: [],
}

function SettingsPanel({
  token,
  tenantId,
  refreshKey,
  onNotify,
  can = () => true,
}) {
  const [settings, setSettings] = useState(DEFAULT_SETTINGS)
  const [tenant, setTenant] = useState(null)
  const [apiKeys, setApiKeys] = useState([])
  const [customDomains, setCustomDomains] = useState([])
  const [newDomain, setNewDomain] = useState({ host: '', kind: 'landing', is_primary: false, cname_target: '' })
  const [savingDomain, setSavingDomain] = useState(false)
  const [newApiKey, setNewApiKey] = useState({ name: '', abilities: 'public:leads:write', expires_at: '' })
  const [creatingKey, setCreatingKey] = useState(false)
  const [settingsTab, setSettingsTab] = useState('branding')
  const canSettingsView = can('settings.view')
  const canSettingsUpdate = can('settings.update')
  const canApiKeysView = can('api_keys.view')
  const canApiKeysCreate = can('api_keys.create')
  const canApiKeysUpdate = can('api_keys.update')
  const canApiKeysDelete = can('api_keys.delete')
  const availableTabs = useMemo(
    () => [
      ...(canSettingsView ? ['branding', 'domains'] : []),
      ...(canApiKeysView ? ['keys'] : []),
    ],
    [canApiKeysView, canSettingsView],
  )

  useEffect(() => {
    if (availableTabs.length === 0) {
      setSettingsTab('')
      return
    }

    if (!availableTabs.includes(settingsTab)) {
      setSettingsTab(availableTabs[0])
    }
  }, [availableTabs, settingsTab])

  const loadSettings = useCallback(async () => {
    if (!tenantId || !canSettingsView) return
    try {
      const keysPromise = canApiKeysView
        ? apiRequest(`/api/admin/api-keys?tenant_id=${tenantId}&per_page=100`, { token, tenantId })
        : Promise.resolve({ data: [] })

      const [settingsResponse, keyResponse, domainResponse] = await Promise.all([
        apiRequest(`/api/admin/settings?tenant_id=${tenantId}`, { token, tenantId }),
        keysPromise,
        apiRequest(`/api/admin/domains?tenant_id=${tenantId}`, { token, tenantId }),
      ])
      setTenant(settingsResponse.tenant ?? null)
      setSettings({
        ...DEFAULT_SETTINGS,
        ...(settingsResponse.settings ?? {}),
        email_delivery: {
          ...DEFAULT_SETTINGS.email_delivery,
          ...(settingsResponse.settings?.email_delivery ?? {}),
          smtp_password: '',
        },
        branding: {
          ...DEFAULT_SETTINGS.branding,
          ...(settingsResponse.settings?.branding ?? settingsResponse.branding ?? {}),
        },
      })
      setCustomDomains(domainResponse.domains ?? settingsResponse.settings?.custom_domains ?? [])
      setApiKeys(keyResponse.data ?? [])
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }, [canApiKeysView, canSettingsView, onNotify, tenantId, token])

  useEffect(() => {
    loadSettings()
  }, [loadSettings, refreshKey])

  const saveSettings = async () => {
    if (!canSettingsUpdate) {
      onNotify('You do not have permission to update settings.', 'warning')
      return
    }

    if (!tenantId) {
      onNotify('Select tenant to save settings.', 'warning')
      return
    }

    try {
      const brandingPayload = Object.fromEntries(
        Object.entries(settings.branding ?? {}).map(([key, value]) => [
          key,
          typeof value === 'string' && value.trim() === '' ? null : value,
        ]),
      )

      const emailDeliveryPayload = {
        ...(settings.email_delivery ?? {}),
      }
      delete emailDeliveryPayload.has_smtp_password

      if (typeof emailDeliveryPayload.smtp_password !== 'string' || emailDeliveryPayload.smtp_password.trim() === '') {
        delete emailDeliveryPayload.smtp_password
      }

      await apiRequest(`/api/admin/settings?tenant_id=${tenantId}`, {
        method: 'PUT',
        token,
        tenantId,
        body: {
          domain: tenant?.domain ?? '',
          timezone: tenant?.timezone ?? 'UTC',
          locale: tenant?.locale ?? 'en',
          currency: tenant?.currency ?? 'USD',
          sso_required: Boolean(tenant?.sso_required),
          providers: settings.providers,
          email_delivery: emailDeliveryPayload,
          domains: settings.domains,
          slack: settings.slack,
          branding: brandingPayload,
        },
      })
      onNotify('Settings saved.', 'success')
      loadSettings()
    } catch (error) {
      onNotify(error.message, 'error')
    }
  }

  const createApiKey = async () => {
    if (!canApiKeysCreate) {
      onNotify('You do not have permission to create API keys.', 'warning')
      return
    }

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

  const createDomain = async () => {
    if (!canSettingsUpdate) {
      onNotify('You do not have permission to manage domains.', 'warning')
      return
    }

    if (!tenantId) {
      onNotify('Select tenant first.', 'warning')
      return
    }

    if (!newDomain.host.trim()) {
      onNotify('Domain host is required.', 'warning')
      return
    }

    setSavingDomain(true)
    try {
      const response = await apiRequest(`/api/admin/domains?tenant_id=${tenantId}`, {
        method: 'POST',
        token,
        tenantId,
        body: {
          host: newDomain.host.trim(),
          kind: newDomain.kind,
          is_primary: newDomain.is_primary,
          cname_target: newDomain.cname_target.trim() || undefined,
        },
      })
      onNotify(
        `Domain added. Point CNAME ${response.dns?.host} -> ${response.dns?.target} then verify.`,
        'success',
      )
      setNewDomain({ host: '', kind: 'landing', is_primary: false, cname_target: '' })
      loadSettings()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSavingDomain(false)
    }
  }

  const verifyDomain = async (domainId) => {
    if (!canSettingsUpdate) {
      onNotify('You do not have permission to verify domains.', 'warning')
      return
    }

    setSavingDomain(true)
    try {
      const response = await apiRequest(`/api/admin/domains/${domainId}/verify?tenant_id=${tenantId}`, {
        method: 'POST',
        token,
        tenantId,
      })

      if (response.domain?.verification_status === 'verified') {
        onNotify(response.message || 'Domain verified successfully.', 'success')
      } else {
        const reason = response.domain?.verification_error || response.message || 'Domain verification failed.'
        onNotify(`Verification failed: ${reason}`, 'error')
      }

      loadSettings()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSavingDomain(false)
    }
  }

  const provisionDomainSsl = async (domainId) => {
    if (!canSettingsUpdate) {
      onNotify('You do not have permission to manage domain SSL.', 'warning')
      return
    }

    setSavingDomain(true)
    try {
      await apiRequest(`/api/admin/domains/${domainId}/ssl/provision?tenant_id=${tenantId}`, {
        method: 'POST',
        token,
        tenantId,
      })
      onNotify('SSL provisioning completed.', 'success')
      loadSettings()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSavingDomain(false)
    }
  }

  const setPrimaryDomain = async (domainId) => {
    if (!canSettingsUpdate) {
      onNotify('You do not have permission to update primary domain.', 'warning')
      return
    }

    setSavingDomain(true)
    try {
      await apiRequest(`/api/admin/domains/${domainId}/primary?tenant_id=${tenantId}`, {
        method: 'POST',
        token,
        tenantId,
      })
      onNotify('Primary domain updated.', 'success')
      loadSettings()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSavingDomain(false)
    }
  }

  const deleteDomain = async (domainId) => {
    if (!canSettingsUpdate) {
      onNotify('You do not have permission to delete domains.', 'warning')
      return
    }

    setSavingDomain(true)
    try {
      await apiRequest(`/api/admin/domains/${domainId}?tenant_id=${tenantId}`, {
        method: 'DELETE',
        token,
        tenantId,
      })
      onNotify('Domain deleted.', 'success')
      loadSettings()
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setSavingDomain(false)
    }
  }

  const revokeApiKey = async (apiKeyId) => {
    if (!canApiKeysUpdate) {
      onNotify('You do not have permission to revoke API keys.', 'warning')
      return
    }

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
    if (!canApiKeysDelete) {
      onNotify('You do not have permission to delete API keys.', 'warning')
      return
    }

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
      {availableTabs.length > 0 && (
        <Tabs
          value={settingsTab}
          onChange={(_, value) => setSettingsTab(value)}
          variant="scrollable"
          allowScrollButtonsMobile
        >
          {canSettingsView && <Tab value="branding" label="Branding & Providers" />}
          {canSettingsView && <Tab value="domains" label="Domains" />}
          {canApiKeysView && <Tab value="keys" label="API Keys" />}
        </Tabs>
      )}
      {availableTabs.length === 0 && (
        <Typography color="text.secondary">
          No settings modules are available for your role.
        </Typography>
      )}

      <Grid container spacing={2}>
        <Grid size={12} sx={{ display: settingsTab === 'branding' ? 'block' : 'none' }}>
          <Card>
            <CardContent>
              <Typography variant="h6">Branding + Providers + Slack</Typography>
              <Divider sx={{ my: 1.2 }} />
              <fieldset style={{ border: 'none', margin: 0, padding: 0 }} disabled={!canSettingsUpdate}>
                <Stack spacing={1.6}>
                <TextField
                  label="Primary Landing Domain"
                  value={tenant?.domain ?? ''}
                  onChange={(event) => setTenant((prev) => ({ ...(prev ?? {}), domain: event.target.value }))}
                />
                <Grid container spacing={1.2}>
                  <Grid size={{ xs: 12, sm: 4 }}>
                    <TextField
                      size="small"
                      fullWidth
                      label="Timezone"
                      value={tenant?.timezone ?? 'UTC'}
                      onChange={(event) => setTenant((prev) => ({ ...(prev ?? {}), timezone: event.target.value }))}
                    />
                  </Grid>
                  <Grid size={{ xs: 12, sm: 4 }}>
                    <TextField
                      size="small"
                      fullWidth
                      label="Locale"
                      value={tenant?.locale ?? 'en'}
                      onChange={(event) => setTenant((prev) => ({ ...(prev ?? {}), locale: event.target.value }))}
                    />
                  </Grid>
                  <Grid size={{ xs: 12, sm: 4 }}>
                    <TextField
                      size="small"
                      fullWidth
                      label="Currency"
                      value={tenant?.currency ?? 'USD'}
                      onChange={(event) => setTenant((prev) => ({ ...(prev ?? {}), currency: event.target.value }))}
                    />
                  </Grid>
                </Grid>
                <TextField
                  label="Logo URL"
                  value={settings.branding?.logo_url ?? ''}
                  onChange={(event) =>
                    setSettings((prev) => ({
                      ...prev,
                      branding: {
                        ...(prev.branding ?? {}),
                        logo_url: event.target.value,
                      },
                    }))
                  }
                />
                <Grid container spacing={1.2}>
                  <Grid size={{ xs: 12, sm: 4 }}>
                    <TextField
                      size="small"
                      fullWidth
                      label="Primary Color"
                      placeholder="#146c94"
                      value={settings.branding?.primary_color ?? ''}
                      onChange={(event) =>
                        setSettings((prev) => ({
                          ...prev,
                          branding: {
                            ...(prev.branding ?? {}),
                            primary_color: event.target.value,
                          },
                        }))
                      }
                    />
                  </Grid>
                  <Grid size={{ xs: 12, sm: 4 }}>
                    <TextField
                      size="small"
                      fullWidth
                      label="Secondary Color"
                      placeholder="#0c4f6c"
                      value={settings.branding?.secondary_color ?? ''}
                      onChange={(event) =>
                        setSettings((prev) => ({
                          ...prev,
                          branding: {
                            ...(prev.branding ?? {}),
                            secondary_color: event.target.value,
                          },
                        }))
                      }
                    />
                  </Grid>
                  <Grid size={{ xs: 12, sm: 4 }}>
                    <TextField
                      size="small"
                      fullWidth
                      label="Accent Color"
                      placeholder="#f59e0b"
                      value={settings.branding?.accent_color ?? ''}
                      onChange={(event) =>
                        setSettings((prev) => ({
                          ...prev,
                          branding: {
                            ...(prev.branding ?? {}),
                            accent_color: event.target.value,
                          },
                        }))
                      }
                    />
                  </Grid>
                </Grid>
                <FormControl size="small" fullWidth>
                  <InputLabel>Landing Theme</InputLabel>
                  <Select
                    label="Landing Theme"
                    value={settings.branding?.landing_theme ?? 'default'}
                    onChange={(event) =>
                      setSettings((prev) => ({
                        ...prev,
                        branding: {
                          ...(prev.branding ?? {}),
                          landing_theme: event.target.value,
                        },
                      }))
                    }
                  >
                    <MenuItem value="default">default</MenuItem>
                    <MenuItem value="modern">modern</MenuItem>
                    <MenuItem value="minimal">minimal</MenuItem>
                    <MenuItem value="enterprise">enterprise</MenuItem>
                  </Select>
                </FormControl>
                <TextField
                  multiline
                  minRows={2}
                  label="Email Footer"
                  value={settings.branding?.email_footer ?? ''}
                  onChange={(event) =>
                    setSettings((prev) => ({
                      ...prev,
                      branding: {
                        ...(prev.branding ?? {}),
                        email_footer: event.target.value,
                      },
                    }))
                  }
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
                      <InputLabel>Email Sender Mode</InputLabel>
                      <Select
                        label="Email Sender Mode"
                        value={settings.email_delivery?.mode ?? 'platform'}
                        onChange={(event) =>
                          setSettings((prev) => ({
                            ...prev,
                            email_delivery: {
                              ...(prev.email_delivery ?? {}),
                              mode: event.target.value,
                            },
                          }))
                        }
                      >
                        <MenuItem value="platform">platform domain</MenuItem>
                        <MenuItem value="tenant">tenant domain</MenuItem>
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

                {settings.email_delivery?.mode === 'tenant' && (
                  <Stack spacing={1.2}>
                    <Typography variant="subtitle2">Tenant Email Sender Profile</Typography>
                    <Grid container spacing={1.2}>
                      <Grid size={{ xs: 12, sm: 6 }}>
                        <TextField
                          size="small"
                          fullWidth
                          label="From Address"
                          placeholder="no-reply@tenant-domain.com"
                          value={settings.email_delivery?.from_address ?? ''}
                          onChange={(event) =>
                            setSettings((prev) => ({
                              ...prev,
                              email_delivery: {
                                ...(prev.email_delivery ?? {}),
                                from_address: event.target.value,
                              },
                            }))
                          }
                        />
                      </Grid>
                      <Grid size={{ xs: 12, sm: 6 }}>
                        <TextField
                          size="small"
                          fullWidth
                          label="From Name"
                          placeholder="Tenant Brand"
                          value={settings.email_delivery?.from_name ?? ''}
                          onChange={(event) =>
                            setSettings((prev) => ({
                              ...prev,
                              email_delivery: {
                                ...(prev.email_delivery ?? {}),
                                from_name: event.target.value,
                              },
                            }))
                          }
                        />
                      </Grid>
                    </Grid>
                    <Stack direction="row" alignItems="center">
                      <Checkbox
                        checked={Boolean(settings.email_delivery?.use_custom_smtp)}
                        onChange={(event) =>
                          setSettings((prev) => ({
                            ...prev,
                            email_delivery: {
                              ...(prev.email_delivery ?? {}),
                              use_custom_smtp: event.target.checked,
                            },
                          }))
                        }
                      />
                      <Typography variant="body2">Use tenant SMTP credentials</Typography>
                    </Stack>
                    {Boolean(settings.email_delivery?.use_custom_smtp) && (
                      <Grid container spacing={1.2}>
                        <Grid size={{ xs: 12, sm: 6 }}>
                          <TextField
                            size="small"
                            fullWidth
                            label="SMTP Host"
                            value={settings.email_delivery?.smtp_host ?? ''}
                            onChange={(event) =>
                              setSettings((prev) => ({
                                ...prev,
                                email_delivery: {
                                  ...(prev.email_delivery ?? {}),
                                  smtp_host: event.target.value,
                                },
                              }))
                            }
                          />
                        </Grid>
                        <Grid size={{ xs: 12, sm: 3 }}>
                          <TextField
                            size="small"
                            fullWidth
                            type="number"
                            label="SMTP Port"
                            value={settings.email_delivery?.smtp_port ?? 587}
                            onChange={(event) =>
                              setSettings((prev) => ({
                                ...prev,
                                email_delivery: {
                                  ...(prev.email_delivery ?? {}),
                                  smtp_port: Number(event.target.value) || 587,
                                },
                              }))
                            }
                          />
                        </Grid>
                        <Grid size={{ xs: 12, sm: 3 }}>
                          <FormControl size="small" fullWidth>
                            <InputLabel>Encryption</InputLabel>
                            <Select
                              label="Encryption"
                              value={settings.email_delivery?.smtp_encryption ?? 'tls'}
                              onChange={(event) =>
                                setSettings((prev) => ({
                                  ...prev,
                                  email_delivery: {
                                    ...(prev.email_delivery ?? {}),
                                    smtp_encryption: event.target.value,
                                  },
                                }))
                              }
                            >
                              <MenuItem value="tls">tls</MenuItem>
                              <MenuItem value="ssl">ssl</MenuItem>
                            </Select>
                          </FormControl>
                        </Grid>
                        <Grid size={{ xs: 12, sm: 6 }}>
                          <TextField
                            size="small"
                            fullWidth
                            label="SMTP Username"
                            value={settings.email_delivery?.smtp_username ?? ''}
                            onChange={(event) =>
                              setSettings((prev) => ({
                                ...prev,
                                email_delivery: {
                                  ...(prev.email_delivery ?? {}),
                                  smtp_username: event.target.value,
                                },
                              }))
                            }
                          />
                        </Grid>
                        <Grid size={{ xs: 12, sm: 6 }}>
                          <TextField
                            size="small"
                            fullWidth
                            type="password"
                            label="SMTP Password"
                            placeholder={
                              settings.email_delivery?.has_smtp_password
                                ? 'Stored password is kept unless you type a new one'
                                : 'Enter SMTP password'
                            }
                            value={settings.email_delivery?.smtp_password ?? ''}
                            onChange={(event) =>
                              setSettings((prev) => ({
                                ...prev,
                                email_delivery: {
                                  ...(prev.email_delivery ?? {}),
                                  smtp_password: event.target.value,
                                },
                              }))
                            }
                          />
                        </Grid>
                      </Grid>
                    )}
                  </Stack>
                )}

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
                <Stack direction="row" alignItems="center">
                  <Checkbox
                    checked={Boolean(tenant?.sso_required)}
                    onChange={(event) => setTenant((prev) => ({ ...(prev ?? {}), sso_required: event.target.checked }))}
                  />
                  <Typography variant="body2">Require SSO login for tenant users</Typography>
                </Stack>
                  <Button variant="contained" startIcon={<SaveIcon />} onClick={saveSettings} disabled={!canSettingsUpdate}>
                    Save Settings
                  </Button>
                </Stack>
              </fieldset>
            </CardContent>
          </Card>
        </Grid>

        <Grid size={12} sx={{ display: settingsTab === 'domains' ? 'block' : 'none' }}>
          <Card>
            <CardContent>
              <Typography variant="h6">Custom Domains</Typography>
              <Divider sx={{ my: 1.2 }} />
              <Stack spacing={1.2}>
                <Grid container spacing={1.2}>
                  <Grid size={{ xs: 12, sm: 5 }}>
                    <TextField
                      size="small"
                      fullWidth
                      label="Domain Host"
                      placeholder="brand.example.com"
                      value={newDomain.host}
                      disabled={!canSettingsUpdate}
                      onChange={(event) => setNewDomain((prev) => ({ ...prev, host: event.target.value }))}
                    />
                  </Grid>
                  <Grid size={{ xs: 12, sm: 3 }}>
                    <FormControl size="small" fullWidth>
                      <InputLabel>Kind</InputLabel>
                      <Select
                        label="Kind"
                        value={newDomain.kind}
                        disabled={!canSettingsUpdate}
                        onChange={(event) => setNewDomain((prev) => ({ ...prev, kind: event.target.value }))}
                      >
                        <MenuItem value="landing">landing</MenuItem>
                        <MenuItem value="admin">admin</MenuItem>
                      </Select>
                    </FormControl>
                  </Grid>
                  <Grid size={{ xs: 12, sm: 2 }}>
                    <Stack direction="row" alignItems="center">
                      <Checkbox
                        checked={newDomain.is_primary}
                        disabled={!canSettingsUpdate}
                        onChange={(event) => setNewDomain((prev) => ({ ...prev, is_primary: event.target.checked }))}
                      />
                      <Typography variant="caption">Primary</Typography>
                    </Stack>
                  </Grid>
                  <Grid size={{ xs: 12, sm: 2 }}>
                    <Button
                      fullWidth
                      variant="contained"
                      onClick={createDomain}
                      disabled={savingDomain || !newDomain.host.trim() || !canSettingsUpdate}
                    >
                      Add
                    </Button>
                  </Grid>
                  <Grid size={12}>
                    <TextField
                      size="small"
                      fullWidth
                      label="CNAME Target (optional override)"
                      placeholder="app.yourdomain.com"
                      value={newDomain.cname_target}
                      disabled={!canSettingsUpdate}
                      onChange={(event) => setNewDomain((prev) => ({ ...prev, cname_target: event.target.value }))}
                      helperText="Leave empty to use system default target."
                    />
                  </Grid>
                </Grid>

                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>Host</TableCell>
                      <TableCell>Kind</TableCell>
                      <TableCell>CNAME Target</TableCell>
                      <TableCell>Verify</TableCell>
                      <TableCell>SSL</TableCell>
                      <TableCell>Details</TableCell>
                      <TableCell>Primary</TableCell>
                      <TableCell align="right">Actions</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {customDomains.map((domain) => (
                      <TableRow key={domain.id}>
                        <TableCell>{domain.host}</TableCell>
                        <TableCell>{domain.kind}</TableCell>
                        <TableCell>{domain.cname_target ?? '-'}</TableCell>
                        <TableCell>{domain.verification_status}</TableCell>
                        <TableCell>{domain.ssl_status}</TableCell>
                        <TableCell>
                          {domain.verification_error || domain.ssl_error || '-'}
                        </TableCell>
                        <TableCell>{domain.is_primary ? 'yes' : 'no'}</TableCell>
                        <TableCell align="right">
                          <Stack direction="row" spacing={1} justifyContent="flex-end">
                            <Button size="small" onClick={() => verifyDomain(domain.id)} disabled={savingDomain || !canSettingsUpdate}>
                              Verify
                            </Button>
                            <Button
                              size="small"
                              onClick={() => provisionDomainSsl(domain.id)}
                              disabled={savingDomain || domain.verification_status !== 'verified' || !canSettingsUpdate}
                            >
                              SSL
                            </Button>
                            {!domain.is_primary && (
                              <Button size="small" onClick={() => setPrimaryDomain(domain.id)} disabled={savingDomain || !canSettingsUpdate}>
                                Primary
                              </Button>
                            )}
                            <Button size="small" color="error" onClick={() => deleteDomain(domain.id)} disabled={savingDomain || !canSettingsUpdate}>
                              Delete
                            </Button>
                          </Stack>
                        </TableCell>
                      </TableRow>
                    ))}
                    {customDomains.length === 0 && (
                      <TableRow>
                        <TableCell colSpan={8}>
                          <Typography align="center" color="text.secondary" sx={{ py: 1.5 }}>
                            No custom domains.
                          </Typography>
                        </TableCell>
                      </TableRow>
                    )}
                  </TableBody>
                </Table>

                <Paper variant="outlined" sx={{ p: 1.2 }}>
                  <Typography variant="subtitle2">Tracking Snippet</Typography>
                  <Typography variant="caption" color="text.secondary">
                    Embed this script in tenant website pages to enable first-party tracking + personalization.
                  </Typography>
                  <TextField
                    size="small"
                    fullWidth
                    value={tenant?.public_key
                      ? `<script async src=\"${import.meta.env.VITE_API_BASE_URL ?? 'http://127.0.0.1:8000'}/t/${tenant.public_key}/tracker.js\"></script>`
                      : 'Public key not generated yet.'}
                    InputProps={{ readOnly: true }}
                    sx={{ mt: 1 }}
                  />
                </Paper>

                <Divider />
                <Typography variant="subtitle2">Assignment Rules Snapshot</Typography>
                {(settings.rules ?? []).length === 0 && <Typography color="text.secondary">No assignment rules found.</Typography>}
                {(settings.rules ?? []).map((rule) => (
                  <Paper key={rule.id} variant="outlined" sx={{ p: 1 }}>
                    <Typography variant="body2">
                      <strong>{rule.name}</strong> - {rule.strategy}
                    </Typography>
                    <Typography variant="caption" color="text.secondary">
                      Priority {rule.priority} - intake {rule.auto_assign_on_intake ? 'on' : 'off'} - import {rule.auto_assign_on_import ? 'on' : 'off'}
                    </Typography>
                  </Paper>
                ))}
              </Stack>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Card sx={{ display: settingsTab === 'keys' ? 'block' : 'none' }}>
        <CardContent>
          <Typography variant="h6">API Keys</Typography>
          <Divider sx={{ my: 1.2 }} />
          {canApiKeysCreate && (
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
          )}
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
                        <Button
                          size="small"
                          color="warning"
                          onClick={() => revokeApiKey(apiKey.id)}
                          disabled={!canApiKeysUpdate}
                        >
                          Revoke
                        </Button>
                      )}
                      <Button size="small" color="error" onClick={() => deleteApiKey(apiKey.id)} disabled={!canApiKeysDelete}>
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
