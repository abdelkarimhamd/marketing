import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Alert,
  Button,
  Card,
  CardContent,
  Chip,
  Divider,
  Grid,
  Paper,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material'
import { Refresh as RefreshIcon } from '@mui/icons-material'
import { apiRequest, formatDate } from '../lib/api'

function summaryCard(label, value, caption) {
  return (
    <Card>
      <CardContent>
        <Typography variant="body2" color="text.secondary" gutterBottom>
          {label}
        </Typography>
        <Typography variant="h4">{value}</Typography>
        <Typography variant="caption" color="text.secondary">
          {caption}
        </Typography>
      </CardContent>
    </Card>
  )
}

function asPercent(value) {
  const parsed = Number(value ?? 0)

  if (!Number.isFinite(parsed)) {
    return '0%'
  }

  return `${parsed.toFixed(2)}%`
}

function issueCount(automation = {}) {
  return Number(automation.failed_messages_last_24h ?? 0)
    + Number(automation.campaign_failures_last_24h ?? 0)
    + Number(automation.queued_stale_messages ?? 0)
    + Number(automation.webhooks_failed_last_24h ?? 0)
    + Number(automation.webhooks_pending_stale ?? 0)
}

function integrationIssueCount(integrations = {}) {
  return Number(integrations.failing_connections ?? 0)
    + Number(integrations.stale_connections ?? 0)
    + Number(integrations.failing_subscriptions ?? 0)
    + Number(integrations.stale_subscriptions ?? 0)
}

function domainIssueCount(domains = {}) {
  return Number(domains.verification_pending ?? 0)
    + Number(domains.verification_failed ?? 0)
    + Number(domains.ssl_pending ?? 0)
    + Number(domains.ssl_failed ?? 0)
}

function severityColor(severity) {
  const key = String(severity ?? '').toLowerCase()

  if (key === 'critical') return 'error'
  if (key === 'warning') return 'warning'
  return 'info'
}

function CustomerSuccessPanel({ token, tenantId, refreshKey, onNotify }) {
  const [loading, setLoading] = useState(false)
  const [data, setData] = useState({
    generated_at: null,
    summary: {},
    tenants: [],
  })
  const [selectedTenantId, setSelectedTenantId] = useState(null)

  const loadConsole = useCallback(async () => {
    setLoading(true)
    try {
      const query = tenantId ? `?tenant_id=${encodeURIComponent(String(tenantId))}` : ''
      const response = await apiRequest(`/api/admin/tenant-console${query}`, {
        token,
        tenantId: tenantId || undefined,
      })

      setData({
        generated_at: response.generated_at ?? null,
        summary: response.summary ?? {},
        tenants: response.tenants ?? [],
      })
    } catch (error) {
      onNotify(error.message, 'error')
    } finally {
      setLoading(false)
    }
  }, [onNotify, tenantId, token])

  useEffect(() => {
    loadConsole()
  }, [loadConsole, refreshKey])

  useEffect(() => {
    const rows = data.tenants ?? []

    if (rows.length === 0) {
      setSelectedTenantId(null)
      return
    }

    const exists = rows.some((row) => String(row.id) === String(selectedTenantId))

    if (!exists) {
      setSelectedTenantId(rows[0].id)
    }
  }, [data.tenants, selectedTenantId])

  const selectedTenant = useMemo(
    () => (data.tenants ?? []).find((row) => String(row.id) === String(selectedTenantId)) ?? null,
    [data.tenants, selectedTenantId],
  )

  const summary = data.summary ?? {}
  const diagnostics = selectedTenant?.diagnostics ?? {}
  const deliverability = diagnostics.deliverability ?? {}
  const automation = diagnostics.automation ?? {}
  const integrations = diagnostics.integrations ?? {}
  const domains = diagnostics.domains ?? {}
  const suggestions = selectedTenant?.fix_suggestions ?? []

  return (
    <Stack spacing={2.4}>
      <Stack direction={{ xs: 'column', md: 'row' }} justifyContent="space-between" spacing={1}>
        <Stack>
          <Typography variant="h5">Customer Success Console</Typography>
          <Typography variant="caption" color="text.secondary">
            Last snapshot: {formatDate(data.generated_at)}
          </Typography>
        </Stack>
        <Button startIcon={<RefreshIcon />} onClick={loadConsole} disabled={loading}>
          Refresh
        </Button>
      </Stack>

      {tenantId && (
        <Alert severity="info">
          Console is filtered to tenant #{tenantId}. Switch to bypass mode to see all tenants.
        </Alert>
      )}

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
          {summaryCard('Tenants', summary.tenants_total ?? 0, 'Visible in console')}
        </Grid>
        <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
          {summaryCard('With Issues', summary.tenants_with_issues ?? 0, 'Any active alerts')}
        </Grid>
        <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
          {summaryCard('Critical Alerts', summary.critical_alerts_total ?? 0, 'Needs immediate action')}
        </Grid>
        <Grid size={{ xs: 12, sm: 6, lg: 3 }}>
          {summaryCard('Integration Issues', summary.tenants_with_integration_issues ?? 0, 'Failing/stale integrations')}
        </Grid>
      </Grid>

      <Grid container spacing={2}>
        <Grid size={{ xs: 12, lg: 8 }}>
          <Card>
            <CardContent sx={{ p: 0 }}>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell>Tenant</TableCell>
                    <TableCell>Health</TableCell>
                    <TableCell>Deliverability</TableCell>
                    <TableCell>Automation</TableCell>
                    <TableCell>Integrations</TableCell>
                    <TableCell>Domains</TableCell>
                    <TableCell>Alerts</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {(data.tenants ?? []).map((tenantRow) => {
                    const rowDiagnostics = tenantRow.diagnostics ?? {}
                    const rowAutomation = rowDiagnostics.automation ?? {}
                    const rowIntegrations = rowDiagnostics.integrations ?? {}
                    const rowDomains = rowDiagnostics.domains ?? {}
                    const rowAlerts = tenantRow.alerts ?? {}

                    return (
                      <TableRow
                        key={tenantRow.id}
                        hover
                        selected={String(tenantRow.id) === String(selectedTenantId)}
                        onClick={() => setSelectedTenantId(tenantRow.id)}
                        sx={{ cursor: 'pointer' }}
                      >
                        <TableCell>
                          <Stack spacing={0.2}>
                            <Typography variant="body2">{tenantRow.name}</Typography>
                            <Typography variant="caption" color="text.secondary">
                              {tenantRow.slug}
                            </Typography>
                          </Stack>
                        </TableCell>
                        <TableCell>{tenantRow.health_score ?? 0}</TableCell>
                        <TableCell>{asPercent(tenantRow.deliverability_rate)}</TableCell>
                        <TableCell>{issueCount(rowAutomation)}</TableCell>
                        <TableCell>{integrationIssueCount(rowIntegrations)}</TableCell>
                        <TableCell>{domainIssueCount(rowDomains)}</TableCell>
                        <TableCell>{rowAlerts.total ?? 0}</TableCell>
                      </TableRow>
                    )
                  })}
                  {(data.tenants ?? []).length === 0 && (
                    <TableRow>
                      <TableCell colSpan={7}>
                        <Typography align="center" color="text.secondary" sx={{ py: 2 }}>
                          No tenants found for this filter.
                        </Typography>
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </Grid>

        <Grid size={{ xs: 12, lg: 4 }}>
          <Card>
            <CardContent>
              <Typography variant="h6">Tenant Diagnostics</Typography>
              <Divider sx={{ my: 1.2 }} />
              {!selectedTenant && (
                <Typography color="text.secondary">Select a tenant to inspect diagnostics.</Typography>
              )}

              {selectedTenant && (
                <Stack spacing={1.2}>
                  <Typography variant="body2">
                    <strong>{selectedTenant.name}</strong> ({selectedTenant.slug})
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    Deliverability: {asPercent(deliverability.deliverability_rate)} / Bounce: {asPercent(deliverability.bounce_rate)}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    Open: {asPercent(deliverability.open_rate)} / Reply: {asPercent(deliverability.reply_rate)}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    Failed msgs (24h): {automation.failed_messages_last_24h ?? 0}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    Queued stale: {automation.queued_stale_messages ?? 0}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    Webhook failed (24h): {automation.webhooks_failed_last_24h ?? 0}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    Integration failures: {(integrations.failing_connections ?? 0) + (integrations.failing_subscriptions ?? 0)}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    Domain verification failed: {domains.verification_failed ?? 0}
                  </Typography>
                  <Typography variant="caption" color="text.secondary">
                    SSL failed: {domains.ssl_failed ?? 0}
                  </Typography>
                </Stack>
              )}
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Card>
        <CardContent>
          <Typography variant="h6">Fix Suggestions</Typography>
          <Divider sx={{ my: 1.2 }} />

          {selectedTenant && suggestions.length === 0 && (
            <Typography color="text.secondary">No active suggestions for this tenant.</Typography>
          )}

          {!selectedTenant && (
            <Typography color="text.secondary">Select a tenant to view action recommendations.</Typography>
          )}

          <Stack spacing={1}>
            {suggestions.map((suggestion) => (
              <Paper key={suggestion.code} variant="outlined" sx={{ p: 1.2 }}>
                <Stack direction="row" spacing={1} alignItems="center" sx={{ mb: 0.6 }}>
                  <Chip size="small" color={severityColor(suggestion.severity)} label={suggestion.severity ?? 'info'} />
                  <Typography variant="subtitle2">{suggestion.title}</Typography>
                </Stack>
                <Typography variant="body2">{suggestion.description}</Typography>
                <Typography variant="caption" color="text.secondary">
                  Action: {suggestion.action}
                </Typography>
              </Paper>
            ))}
          </Stack>
        </CardContent>
      </Card>
    </Stack>
  )
}

export default CustomerSuccessPanel
